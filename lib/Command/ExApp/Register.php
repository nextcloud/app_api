<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\DeployActions\DockerActions;
use OCA\AppEcosystemV2\DeployActions\ManualActions;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\DaemonConfigService;
use OCA\AppEcosystemV2\Service\ExAppApiScopeService;
use OCA\AppEcosystemV2\Service\ExAppScopesService;
use OCA\AppEcosystemV2\Service\ExAppUsersService;

use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Register extends Command {
	private AppEcosystemV2Service $service;
	private DaemonConfigService $daemonConfigService;
	private ExAppApiScopeService $exAppApiScopeService;
	private ExAppScopesService $exAppScopesService;
	private ExAppUsersService $exAppUsersService;
	private DockerActions $dockerActions;
	private ManualActions $manualActions;

	public function __construct(
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		ExAppApiScopeService $exAppApiScopeService,
		ExAppScopesService $exAppScopesService,
		ExAppUsersService $exAppUsersService,
		DockerActions $dockerActions,
		ManualActions $manualActions,
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->exAppApiScopeService = $exAppApiScopeService;
		$this->exAppScopesService = $exAppScopesService;
		$this->exAppUsersService = $exAppUsersService;
		$this->dockerActions = $dockerActions;
		$this->manualActions = $manualActions;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:register');
		$this->setDescription('Register external app');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-name', InputArgument::REQUIRED);

		$this->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Enable ExApp after registration');
		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force scopes approval');
		$this->addOption('json-info', null, InputOption::VALUE_REQUIRED, 'ExApp JSON deploy info');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		if ($this->service->getExApp($appId) !== null) {
			$output->writeln(sprintf('ExApp %s already registered.', $appId));
			return 2;
		}

		$daemonConfigName = $input->getArgument('daemon-config-name');
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			return 2;
		}

		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$exAppInfo = $this->dockerActions->loadExAppInfo($appId, $daemonConfig);
		} elseif ($daemonConfig->getAcceptsDeployId() === $this->manualActions->getAcceptsDeployId()) {
			$exAppJson = $input->getOption('json-info');
			if ($exAppJson === null) {
				$output->writeln('ExApp JSON is required for manual deploy.');
				return 2;
			}

			$exAppInfo = $this->manualActions->loadExAppInfo($appId, $daemonConfig, [
				'json-info' => $exAppJson,
			]);
		} else {
			$output->writeln(sprintf('Daemon config %s actions for %s not found.', $daemonConfigName, $daemonConfig->getAcceptsDeployId()));
			return 2;
		}

		$appId = $exAppInfo['appid'];
		$version = $exAppInfo['version'];
		$name = $exAppInfo['name'];
		$protocol = $exAppInfo['protocol'] ?? 'http';
		$port = (int) $exAppInfo['port'];
		$host = $exAppInfo['host'];
		$secret = $exAppInfo['secret'];

		$exApp = $this->service->registerExApp($appId, [
			'version' => $version,
			'name' => $name,
			'daemon_config_name' => $daemonConfigName,
			'protocol' => $protocol,
			'host' => $host,
			'port' => $port,
			'secret' => $secret,
		]);

		if ($exApp !== null) {
			$output->writeln(sprintf('ExApp %s successfully registered.', $appId));

			if (filter_var($exAppInfo['system_app'], FILTER_VALIDATE_BOOLEAN)) {
				try {
					$this->exAppUsersService->setupSystemAppFlag($exApp);
				} catch (Exception $e) {
					$output->writeln(sprintf('Error while setting app system flag: %s', $e->getMessage()));
					return 1;
				}
			}

			$requestedExAppScopeGroups = $exAppInfo['scopes'] ?? $this->service->getExAppRequestedScopes($exApp);
			if (isset($requestedExAppScopeGroups['error'])) {
				$output->writeln($requestedExAppScopeGroups['error']);
				// Fallback unregistering ExApp
				$this->service->unregisterExApp($exApp->getAppid());
				return 2;
			}

			$forceScopes = (bool) $input->getOption('force-scopes');
			$confirmRequiredScopes = $forceScopes;
			$confirmOptionalScopes = $forceScopes;

			if (!$forceScopes && $input->isInteractive()) {
				/** @var QuestionHelper $helper */
				$helper = $this->getHelper('question');

				// Prompt to approve required ExApp scopes
				if (count($requestedExAppScopeGroups['required']) > 0) {
					$output->writeln(sprintf('ExApp %s requested required scopes: %s', $appId, implode(', ',
						$this->exAppApiScopeService->mapScopeGroupsToNames($requestedExAppScopeGroups['required']))));
					$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
					$confirmRequiredScopes = $helper->ask($input, $output, $question);
				}

				// Prompt to approve optional ExApp scopes
				if ($confirmRequiredScopes && count($requestedExAppScopeGroups['optional']) > 0) {
					$output->writeln(sprintf('ExApp %s requested optional scopes: %s', $appId, implode(', ',
						$this->exAppApiScopeService->mapScopeGroupsToNames($requestedExAppScopeGroups['optional']))));
					$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
					$confirmOptionalScopes = $helper->ask($input, $output, $question);
				}
			}

			if (!$confirmRequiredScopes && count($requestedExAppScopeGroups['required']) > 0) {
				$output->writeln(sprintf('ExApp %s required scopes not approved.', $appId));
				// Fallback unregistering ExApp
				$this->service->unregisterExApp($exApp->getAppid());
				return 1;
			}

			if (count($requestedExAppScopeGroups['required']) > 0) {
				$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['required']);
			}
			if ($confirmOptionalScopes && count($requestedExAppScopeGroups['optional']) > 0) {
				$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['optional'], false);
			}

			$enabled = (bool) $input->getOption('enabled');
			if ($enabled) {
				if ($this->service->enableExApp($exApp)) {
					$output->writeln(sprintf('ExApp %s successfully enabled.', $appId));
				} else {
					$output->writeln(sprintf('Failed to enable ExApp %s.', $appId));
					// Fallback unregistering ExApp
					$this->service->unregisterExApp($exApp->getAppid());
					return 1;
				}
			}

			return 0;
		}

		$output->writeln(sprintf('Failed to register ExApp %s.', $appId));
		return 1;
	}

	private function registerExAppScopes($output, ExApp $exApp, array $requestedExAppScopeGroups, bool $required = true): void {
		$scopeType = $required ? 'required' : 'optional';
		$registeredScopeGroups = [];
		foreach ($requestedExAppScopeGroups as $scopeGroup) {
			if ($this->exAppScopesService->setExAppScopeGroup($exApp, $scopeGroup)) {
				$registeredScopeGroups[] = $scopeGroup;
			} else {
				$output->writeln(sprintf('Failed to set %s ExApp scope group: %s', $scopeType, $scopeGroup));
			}
		}
		if (count($registeredScopeGroups) > 0) {
			$output->writeln(sprintf('ExApp %s %s scope groups successfully set: %s', $exApp->getAppid(), $scopeType, implode(', ',
				$this->exAppApiScopeService->mapScopeGroupsToNames($registeredScopeGroups))));
		}
	}
}
