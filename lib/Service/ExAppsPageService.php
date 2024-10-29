<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class ExAppsPageService {

	public function __construct(
		private readonly ExAppFetcher $exAppFetcher,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly DockerActions $dockerActions,
		private readonly IConfig $config,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Helper method for settings app to provide initial state for Apps management UI
	 *
	 * @since 30.0.2
	 */
	public function provideAppApiState(IInitialState $initialState): void {
		$appApiEnabled = $this->appManager->isInstalled('app_api');
		$initialState->provideInitialState('appApiEnabled', $appApiEnabled);
		$daemonConfigAccessible = false;
		$defaultDaemonConfig = null;

		if ($appApiEnabled) {
			$initialState->provideInitialState('appstoreExAppUpdateCount', count($this->exAppFetcher->getExAppsWithUpdates()));

			$defaultDaemonConfigName = $this->config->getAppValue('app_api', 'default_daemon_config');
			if ($defaultDaemonConfigName !== '') {
				$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($defaultDaemonConfigName);
				if ($daemonConfig !== null) {
					$defaultDaemonConfig = $daemonConfig->jsonSerialize();
					unset($defaultDaemonConfig['deploy_config']['haproxy_password']);
					$this->dockerActions->initGuzzleClient($daemonConfig);
					$daemonConfigAccessible = $this->dockerActions->ping($this->dockerActions->buildDockerUrl($daemonConfig));
					if (!$daemonConfigAccessible) {
						$this->logger->warning(sprintf('Deploy daemon "%s" is not accessible by Nextcloud. Please verify its configuration', $daemonConfig->getName()));
					}
				}
			}
		}

		$initialState->provideInitialState('defaultDaemonConfigAccessible', $daemonConfigAccessible);
		$initialState->provideInitialState('defaultDaemonConfig', $defaultDaemonConfig);
	}
}
