<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;

use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Register extends Command {

	public function __construct(
		private readonly AppAPIService  	  $service,
		private readonly DaemonConfigService  $daemonConfigService,
		private readonly DockerActions        $dockerActions,
		private readonly ManualActions        $manualActions,
		private readonly IAppConfig           $appConfig,
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

		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force scopes approval[deprecated]');
		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, 'Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('json-info', null, InputOption::VALUE_REQUIRED, 'ExApp info.xml in JSON format');
		$this->addOption('wait-finish', null, InputOption::VALUE_NONE, 'Wait until finish');
		$this->addOption('silent', null, InputOption::VALUE_NONE, 'Do not print to console');
		$this->addOption('test-deploy-mode', null, InputOption::VALUE_NONE, 'Test deploy mode with additional status checks and slightly different logic');

		// Advanced deploy options
		$this->addOption('env', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Optional deploy options (ENV_NAME=ENV_VALUE), passed to ExApp container as environment variables');
		$this->addOption('mount', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Optional mount options (SRC_PATH:DST_PATH or SRC_PATH:DST_PATH:ro|rw), passed to ExApp container as volume mounts only if the app declares those variables in its info.xml');
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

		$deployOptions = [];
		$envs = $input->getOption('env') ?? [];
		// Parse array of deploy options strings (ENV_NAME=ENV_VALUE) to array key => value
		$envs = array_reduce($envs, function ($carry, $item) {
			$parts = explode('=', $item, 2);
			if (count($parts) === 2) {
				$carry[$parts[0]] = $parts[1];
			}
			return $carry;
		}, []);
		$deployOptions['environment_variables'] = $envs;

		$mounts = $input->getOption('mount') ?? [];
		// Parse array of mount options strings (HOST_PATH:CONTAINER_PATH:ro|rw)
		// to array of arrays ['source' => HOST_PATH, 'target' => CONTAINER_PATH, 'mode' => ro|rw]
		$mounts = array_reduce($mounts, function ($carry, $item) {
			$parts = explode(':', $item, 3);
			if (count($parts) === 3) {
				$carry[] = ['source' => $parts[0], 'target' => $parts[1], 'mode' => $parts[2]];
			} elseif (count($parts) === 2) {
				$carry[] = ['source' => $parts[0], 'target' => $parts[1], 'mode' => 'rw'];
			}
			return $carry;
		}, );
		$deployOptions['mounts'] = $mounts;

		$appInfo = $this->exAppService->getAppInfo(
			$appId, $input->getOption('info-xml'), $input->getOption('json-info'),
			$deployOptions
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
		if (!isset($daemonConfigName) || $daemonConfigName === '') {
			$daemonConfigName = $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', lazy: true);
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

		$appInfo['port'] = $appInfo['port'] ?? $this->exAppService->getExAppFreePort();
		$appInfo['secret'] = $appInfo['secret'] ?? $this->random->generate(128);
		$appInfo['daemon_config_name'] = $appInfo['daemon_config_name'] ?? $daemonConfigName;
		$exApp = $this->exAppService->registerExApp($appInfo);
		if (!$exApp) {
			$this->logger->error(sprintf('Error during registering ExApp %s.', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('Error during registering ExApp %s.', $appId));
			}
			return 3;
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
			if (boolval($exApp->getDeployConfig()['harp'] ?? false)) {
				$deployResult = $this->dockerActions->deployExAppHarp($exApp, $daemonConfig, $deployParams);
			} else {
				$deployResult = $this->dockerActions->deployExApp($exApp, $daemonConfig, $deployParams);
			}
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
