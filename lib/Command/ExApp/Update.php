<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;

use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Update extends Command {

	public function __construct(
		private readonly AppAPIService  	       $service,
		private readonly ExAppService              $exAppService,
		private readonly DaemonConfigService       $daemonConfigService,
		private readonly DockerActions             $dockerActions,
		private readonly ManualActions             $manualActions,
		private readonly LoggerInterface           $logger,
		private readonly ExAppArchiveFetcher       $exAppArchiveFetcher,
		private readonly ExAppFetcher		       $exAppFetcher,
		private readonly ExAppDeployOptionsService $exAppDeployOptionsService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:update');
		$this->setDescription('Update ExApp');

		$this->addArgument('appid', InputArgument::OPTIONAL, 'Update the specified app');

		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, 'Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('json-info', null, InputOption::VALUE_REQUIRED, 'ExApp info.xml in JSON format');
		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force new ExApp scopes approval[deprecated]');
		$this->addOption('wait-finish', null, InputOption::VALUE_NONE, 'Wait until finish');
		$this->addOption('silent', null, InputOption::VALUE_NONE, 'Do not print to console');
		$this->addOption('all', null, InputOption::VALUE_NONE, 'Updates all enabled and updatable apps');
		$this->addOption('showonly', null, InputOption::VALUE_NONE, 'Additional flag for "--all" to only show all updatable apps');
		$this->addOption('include-disabled', null, InputOption::VALUE_NONE, 'Additional flag for "--all" to also update disabled apps');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		if (empty($appId) && !$input->getOption('all')) {
			$output->writeln("<error>Please specify an app to update or \"--all\" to update all updatable apps</error>");
			return 1;
		} elseif (!empty($appId) && $input->getOption('all')) {
			$output->writeln("<error>The \"--all\" flag is mutually exclusive with specifying app</error>");
			return 1;
		} elseif ($input->getOption('all')) {
			$apps = $this->exAppFetcher->get();
			$appsWithUpdates = array_filter($apps, function (array $app) {
				$exApp = $this->exAppService->getExApp($app['id']);
				$newestVersion = $app['releases'][0]['version'];
				return $exApp !== null && isset($app['releases'][0]['version']) && version_compare($newestVersion, $exApp->getVersion(), '>');
			});
			if ($input->getOption('showonly')) {
				foreach ($appsWithUpdates as $appWithUpdate) {
					$output->writeln($appWithUpdate['id'] . ' new version available: ' . $appWithUpdate['releases'][0]['version']);
				}
				return 0;
			}
			$return = 0;
			foreach ($appsWithUpdates as $appWithUpdate) {
				$result = $this->updateExApp($input, $output, $appWithUpdate['id']);
				if ($result > 0) {
					$return = $result;
				}
			}
			return $return;
		}
		return $this->updateExApp($input, $output, $appId);
	}

	private function updateExApp(InputInterface $input, OutputInterface $output, string $appId): int {
		$outputConsole = !$input->getOption('silent');
		$deployOptions = $this->exAppDeployOptionsService->formatDeployOptions(
			$this->exAppDeployOptionsService->getDeployOptions()
		);
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

		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$this->logger->error(sprintf('ExApp %s not found.', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('ExApp %s not found.', $appId));
			}
			return 1;
		}

		$includeDisabledApps = $input->getOption('include-disabled');
		if ($input->getOption('all') && !$exApp->getEnabled() && !$includeDisabledApps) {
			$this->logger->info(sprintf('ExApp %s is disabled. Update skipped (use --include-disabled to update disabled apps).', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('ExApp %s is disabled. Update skipped (use --include-disabled to update disabled apps).', $appId));
			}
			return 0;
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig === null) {
			$this->logger->error(sprintf('Daemon config %s not found.', $exApp->getDaemonConfigName()));
			if ($outputConsole) {
				$output->writeln(sprintf('Daemon config %s not found.', $exApp->getDaemonConfigName()));
			}
			return 2;
		}

		$actionsDeployIds = [
			$this->dockerActions->getAcceptsDeployId(),
			$this->manualActions->getAcceptsDeployId(),
		];
		if (!in_array($daemonConfig->getAcceptsDeployId(), $actionsDeployIds)) {
			$this->logger->error(sprintf('Daemon config %s actions for %s not found.', $daemonConfig->getName(), $daemonConfig->getAcceptsDeployId()));
			if ($outputConsole) {
				$output->writeln(sprintf('Daemon config %s actions for %s not found.', $daemonConfig->getName(), $daemonConfig->getAcceptsDeployId()));
			}
			return 2;
		}

		if ($exApp->getVersion() === $appInfo['version']) {
			$this->logger->warning(sprintf('ExApp %s is already updated (%s)', $appId, $appInfo['version']));
			if ($outputConsole) {
				$output->writeln(sprintf('ExApp %s is already updated (%s)', $appId, $appInfo['version']));
			}
			return 0;
		}

		$status = $exApp->getStatus();
		$status['type'] = 'update';
		$status['error'] = '';
		$exApp->setStatus($status);
		$this->exAppService->updateExApp($exApp, ['status']);

		$wasEnabled = $exApp->getEnabled();
		if ($wasEnabled) {
			if ($this->service->disableExApp($exApp)) {
				$this->logger->info(sprintf('ExApp %s successfully disabled.', $appId));
				if ($outputConsole) {
					$output->writeln(sprintf('ExApp %s successfully disabled.', $appId));
				}
			}
		} else {
			$this->logger->info(sprintf('ExApp %s was already disabled.', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('ExApp %s was already disabled.', $appId));
			}
		}

		$this->exAppService->removeExAppRoutes($exApp);
		if (isset($appInfo['external-app']['routes'])) {
			$this->exAppService->registerExAppRoutes($exApp, $appInfo['external-app']['routes']);
		}

		if (!empty($appInfo['external-app']['translations_folder'])) {
			$result = $this->exAppArchiveFetcher->installTranslations($appId, $appInfo['external-app']['translations_folder']);
			if ($result) {
				$this->logger->error(sprintf('Failed to install translations for %s. Reason: %s', $appId, $result));
				if ($outputConsole) {
					$output->writeln(sprintf('Failed to install translations for %s. Reason: %s', $appId, $result));
				}
			}
		}

		if (!$this->exAppService->updateExAppInfo($exApp, $appInfo)) {
			$this->logger->error(sprintf('Failed to update ExApp %s info', $appId));
			if ($outputConsole) {
				$output->writeln(sprintf('Failed to update ExApp %s info', $appId));
			}
			$this->exAppService->setStatusError($exApp, 'Failed to update info');
			return 1;
		}

		$appInfo['port'] = $exApp->getPort();
		$appInfo['secret'] = $exApp->getSecret();
		$auth = [];
		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $appInfo);
			if (boolval($exApp->getDeployConfig()['harp'] ?? false)) {
				$deployResult = $this->dockerActions->deployExAppHarp($exApp, $daemonConfig, $deployParams);
			} else {
				$deployResult = $this->dockerActions->deployExApp($exApp, $daemonConfig, $deployParams);
			}
			if ($deployResult) {
				$this->logger->error(sprintf('ExApp %s deployment update failed. Error: %s', $appId, $deployResult));
				if ($outputConsole) {
					$output->writeln(sprintf('ExApp %s deployment update failed. Error: %s', $appId, $deployResult));
				}
				$this->exAppService->setStatusError($exApp, 'Deployment update failed');
				return 1;
			}

			if (!$this->dockerActions->healthcheckContainer($this->dockerActions->buildExAppContainerName($appId), $daemonConfig, true)) {
				$this->logger->error(sprintf('ExApp %s update failed. Error: %s', $appId, 'Container healthcheck failed.'));
				if ($outputConsole) {
					$output->writeln(sprintf('ExApp %s update failed. Error: %s', $appId, 'Container healthcheck failed.'));
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

		$this->logger->info(sprintf('ExApp %s update successfully deployed.', $appId));
		if ($outputConsole) {
			$output->writeln(sprintf('ExApp %s update successfully deployed.', $appId));
		}

		$this->service->dispatchExAppInitInternal($exApp);
		if ($input->getOption('wait-finish')) {
			$error = $this->exAppService->waitInitStepFinish($appId);
			if ($error) {
				$output->writeln($error);
				return 1;
			}
		}
		$this->logger->info(sprintf('ExApp %s successfully updated.', $appId));
		if ($outputConsole) {
			$output->writeln(sprintf('ExApp %s successfully updated.', $appId));
		}

		if ($includeDisabledApps) {
			$exApp = $this->exAppService->getExApp($appId);
			if (!$wasEnabled && $exApp->getEnabled()) {
				if ($this->service->disableExApp($exApp)) {
					$this->logger->info(sprintf('ExApp %s successfully disabled after update.', $appId));
					if ($outputConsole) {
						$output->writeln(sprintf('ExApp %s successfully disabled after update.', $appId));
					}
				}
			}
		}

		return 0;
	}
}
