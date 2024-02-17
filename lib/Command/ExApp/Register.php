<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppApiScopeService;
use OCA\AppAPI\Service\ExAppScopesService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ExAppUsersService;

use OCP\DB\Exception;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Register extends Command {

	public function __construct(
		private readonly AppAPIService  	  $service,
		private readonly DaemonConfigService  $daemonConfigService,
		private readonly ExAppScopesService   $exAppScopesService,
		private readonly ExAppApiScopeService $exAppApiScopeService,
		private readonly ExAppUsersService    $exAppUsersService,
		private readonly DockerActions        $dockerActions,
		private readonly ManualActions        $manualActions,
		private readonly IConfig              $config,
		private readonly ExAppService         $exAppService,
		private readonly ISecureRandom        $random,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:register');
		$this->setDescription('Install external App');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-name', InputArgument::OPTIONAL);

		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force scopes approval');
		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, 'Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('json-info', null, InputOption::VALUE_REQUIRED, 'ExApp info.xml in JSON format');
		$this->addOption('wait-finish', null, InputOption::VALUE_NONE, 'Wait until finish');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		if ($this->exAppService->getExApp($appId) !== null) {
			$output->writeln(sprintf('ExApp %s already registered.', $appId));
			return 3;
		}

		$appInfo = $this->exAppService->getAppInfo(
			$appId, $input->getOption('info-xml'), $input->getOption('json-info')
		);
		if (isset($appInfo['error'])) {
			$output->writeln($appInfo['error']);
			return 1;
		}
		$appId = $appInfo['id'];  # value from $appInfo should have higher priority

		$daemonConfigName = $input->getArgument('daemon-config-name');
		if (!isset($daemonConfigName)) {
			$daemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config');
		}
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			return 2;
		}

		$forceScopes = (bool) $input->getOption('force-scopes');
		$confirmRequiredScopes = $forceScopes;
		if (!$forceScopes && $input->isInteractive()) {
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');

			// Prompt to approve required ExApp scopes
			if (count($appInfo['external-app']['scopes']) > 0) {
				$output->writeln(
					sprintf('ExApp %s requested required scopes: %s', $appId, implode(', ', $appInfo['external-app']['scopes']))
				);
				$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
				$confirmRequiredScopes = $helper->ask($input, $output, $question);
			} else {
				$confirmRequiredScopes = true;
			}
		}

		if (!$confirmRequiredScopes && count($appInfo['external-app']['scopes']) > 0) {
			$output->writeln(sprintf('ExApp %s required scopes not approved.', $appId));
			return 1;
		}

		$appInfo['port'] = $appInfo['port'] ?? $this->exAppService->getExAppFreePort();
		$appInfo['secret'] = $appInfo['secret'] ?? $this->random->generate(128);
		$appInfo['daemon_config_name'] = $appInfo['daemon_config_name'] ?? $daemonConfigName;
		$exApp = $this->exAppService->registerExApp($appInfo);
		if (!$exApp) {
			$output->writeln(sprintf('Error during registering ExApp %s.', $appId));
			return 3;
		}
		if (filter_var($appInfo['external-app']['system'], FILTER_VALIDATE_BOOLEAN)) {
			# TO-DO: refactor in next version: move "system" to the "ex_apps" table as a separate field.
			try {
				$this->exAppUsersService->setupSystemAppFlag($appId);
			} catch (Exception $e) {
				$this->exAppService->unregisterExApp($appId);
				$output->writeln(sprintf('Error while setting app system flag: %s', $e->getMessage()));
				return 1;
			}
		}
		if (count($appInfo['external-app']['scopes']) > 0) {
			if (!$this->exAppScopesService->registerExAppScopes(
				$exApp, $this->exAppApiScopeService->mapScopeNamesToNumbers($appInfo['external-app']['scopes']))
			) {
				$this->exAppService->unregisterExApp($appId);
				$output->writeln('Error while registering API scopes.');
				return 1;
			}
			$output->writeln(
				sprintf('ExApp %s scope groups successfully set: %s',
					$exApp->getAppid(), implode(', ', $appInfo['external-app']['scopes'])
				)
			);
		}

		$auth = [];
		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $appInfo);
			[$pullResult, $createResult, $startResult] = $this->dockerActions->deployExApp($exApp, $daemonConfig, $deployParams);
			if (isset($pullResult['error'])) {
				$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $pullResult['error']));
				return 1;
			}

			if (!isset($startResult['error']) && isset($createResult['Id'])) {
				if (!$this->dockerActions->healthcheckContainer($this->dockerActions->buildExAppContainerName($appId), $daemonConfig)) {
					$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, 'Container healthcheck failed.'));
					return 1;
				}

				$exAppUrl = $this->dockerActions->resolveExAppUrl(
					$appId,
					$daemonConfig->getProtocol(),
					$daemonConfig->getHost(),
					$daemonConfig->getDeployConfig(),
					(int)explode('=', $deployParams['container_params']['env'][6])[1],
					$auth,
				);
			} else {
				$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $startResult['error'] ?? $createResult['error']));
				return 3;
			}
		} elseif ($daemonConfig->getAcceptsDeployId() === $this->manualActions->getAcceptsDeployId()) {
			$exAppUrl = $this->manualActions->resolveExAppUrl(
				$appId,
				$daemonConfig->getProtocol(),
				$daemonConfig->getHost(),
				$daemonConfig->getDeployConfig(),
				(int) $appInfo['port'],
				$auth,
			);
		} else {
			$output->writeln(sprintf('Daemon config %s actions for %s not found.', $daemonConfigName, $daemonConfig->getAcceptsDeployId()));
			return 2;
		}

		if (!$this->service->heartbeatExApp($exAppUrl, $auth)) {
			$output->writeln(sprintf('ExApp %s heartbeat check failed. Make sure ExApp was started and initialized manually.', $appId));
			return 2;
		}
		$output->writeln(sprintf('ExApp %s deployed successfully.', $appId));

		$this->service->dispatchExAppInitInternal($exApp);
		if ($input->getOption('wait-finish')) {
			$error = $this->exAppService->waitInitStepFinish($appId);
			if ($error) {
				$output->writeln($error);
				return 1;
			}
		}
		$output->writeln(sprintf('ExApp %s successfully registered.', $appId));
		return 0;
	}
}
