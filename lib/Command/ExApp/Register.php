<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppApiScopeService;
use OCA\AppAPI\Service\ExAppService;

use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
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
		private readonly ExAppApiScopeService $exAppApiScopeService,
		private readonly DockerActions        $dockerActions,
		private readonly ManualActions        $manualActions,
		private readonly IConfig              $config,
		private readonly ExAppService         $exAppService,
		private readonly ISecureRandom        $random,
		private readonly LoggerInterface      $logger,
		private readonly ExAppArchiveFetcher  $exAppArchiveFetcher,
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
		$this->addOption('silent', null, InputOption::VALUE_NONE, 'Do not print to console');
		$this->addOption('test-deploy-mode', null, InputOption::VALUE_NONE, 'Test deploy mode with additional status checks and slightly different logic');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$outputConsole = !$input->getOption('silent');
		$isTestDeployMode = $input->getOption('test-deploy-mode');
		$appId = $input->getArgument('appid');

		if ($this->exAppService->getExApp($appId) !== null) {
			if (!$isTestDeployMode) {
				$this->logger->error(sprintf('ExApp %s is already registered.', $appId));
				if ($outputConsole) {
					$output->writeln(sprintf('ExApp %s is already registered.', $appId));
				}
				return 3;
			}
			$this->exAppService->unregisterExApp($appId);
		}

		$appInfo = $this->exAppService->getAppInfo(
			$appId, $input->getOption('info-xml'), $input->getOption('json-info')
		);
		if (isset($appInfo['error'])) {
			$this->logger->error($appInfo['error']);
			if ($outputConsole) {
				$output->writeln($appInfo['error']);
			}
			return 1;
		}
		$appId = $appInfo['id'];  # value from $appInfo should have higher priority

		$daemonConfigName = $input->getArgument('daemon-config-name');
		if (!isset($daemonConfigName)) {
			$daemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config');
		}
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$this->logger->error(sprintf('Daemon config %s not found.', $daemonConfigName));
			if ($outputConsole) {
				$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			}
			return 2;
		}

		$actionsDeployIds = [
			$this->dockerActions->getAcceptsDeployId(),
			$this->manualActions->getAcceptsDeployId(),
		];
		if (!in_array($daemonConfig->getAcceptsDeployId(), $actionsDeployIds)) {
			$this->logger->error(sprintf('Daemon config %s actions for %s not found.', $daemonConfigName, $daemonConfig->getAcceptsDeployId()));
			if ($outputConsole) {
				$output->writeln(sprintf('Daemon config %s actions for %s not found.', $daemonConfigName, $daemonConfig->getAcceptsDeployId()));
			}
			return 2;
		}

		$confirmRequiredScopes = (bool) $input->getOption('force-scopes');
		if (!$confirmRequiredScopes && $input->isInteractive()) {
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
		$appInfo['api_scopes'] = array_values($this->exAppApiScopeService->mapScopeGroupsToNumbers($appInfo['external-app']['scopes']));
		$exApp = $this->exAppService->registerExApp($appInfo);
		if (!$exApp) {
			$this->logger->error(sprintf('Error during registering ExApp %s.', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('Error during registering ExApp %s.', $appId));
			}
			return 3;
		}
		if (count($appInfo['external-app']['scopes']) > 0) {
			$this->logger->info(
				sprintf('ExApp %s scope groups successfully set: %s', $exApp->getAppid(), implode(', ', $appInfo['external-app']['scopes']))
			);
			if ($outputConsole) {
				$output->writeln(
					sprintf('ExApp %s scope groups successfully set: %s', $exApp->getAppid(), implode(', ', $appInfo['external-app']['scopes']))
				);
			}
		}

		if (!empty($appInfo['external-app']['translations_folder'])) {
			$result = $this->exAppArchiveFetcher->installTranslations($appId, $appInfo['external-app']['translations_folder']);
			if ($result) {
				$this->logger->error(sprintf('Failed to install translations for %s. Reason: %s', $appId, $result));
				if ($outputConsole) {
					$output->writeln(sprintf('Failed to install translations for %s. Reason: %s', $appId, $result));
				}
				$this->_unregisterExApp($appId, $isTestDeployMode);
				return 3;
			}
		}

		$auth = [];
		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $appInfo);
			$deployResult = $this->dockerActions->deployExApp($exApp, $daemonConfig, $deployParams);
			if ($deployResult) {
				$this->logger->error(sprintf('ExApp %s deployment failed. Error: %s', $appId, $deployResult));
				if ($outputConsole) {
					$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $deployResult));
				}
				$this->exAppService->setStatusError($exApp, $deployResult);
				$this->_unregisterExApp($appId, $isTestDeployMode);
				return 1;
			}

			if (!$this->dockerActions->healthcheckContainer($this->dockerActions->buildExAppContainerName($appId), $daemonConfig, true)) {
				$this->logger->error(sprintf('ExApp %s deployment failed. Error: %s', $appId, 'Container healthcheck failed.'));
				if ($outputConsole) {
					$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, 'Container healthcheck failed.'));
				}
				$this->exAppService->setStatusError($exApp, 'Container healthcheck failed');
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
			$this->manualActions->deployExApp($exApp, $daemonConfig);
			$exAppUrl = $this->manualActions->resolveExAppUrl(
				$appId,
				$daemonConfig->getProtocol(),
				$daemonConfig->getHost(),
				$daemonConfig->getDeployConfig(),
				(int) $appInfo['port'],
				$auth,
			);
		}

		if (!$this->service->heartbeatExApp($exAppUrl, $auth, $appId)) {
			$this->logger->error(sprintf('ExApp %s heartbeat check failed. Make sure that Nextcloud instance and ExApp can reach it other.', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('ExApp %s heartbeat check failed. Make sure that Nextcloud instance and ExApp can reach it other.', $appId));
			}
			$this->exAppService->setStatusError($exApp, 'Heartbeat check failed');
			return 1;
		}
		$this->logger->info(sprintf('ExApp %s deployed successfully.', $appId));
		if ($outputConsole) {
			$output->writeln(sprintf('ExApp %s deployed successfully.', $appId));
		}

		$this->service->dispatchExAppInitInternal($exApp);
		if ($input->getOption('wait-finish')) {
			$error = $this->exAppService->waitInitStepFinish($appId);
			if ($error) {
				$output->writeln($error);
				return 1;
			}
		}
		$this->logger->info(sprintf('ExApp %s successfully registered.', $appId));
		if ($outputConsole) {
			$output->writeln(sprintf('ExApp %s successfully registered.', $appId));
		}
		return 0;
	}

	private function _unregisterExApp(string $appId, bool $testDeployMode = false): void {
		if ($testDeployMode) {
			return;
		}
		$this->exAppService->unregisterExApp($appId);
	}
}
