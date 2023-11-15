<?php

declare(strict_types=1);

namespace OCA\AppAPI\Settings;

use OCA\AppAPI\AppInfo\Application;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use Psr\Log\LoggerInterface;

class Admin implements ISettings {
	private IInitialState $initialStateService;
	private DaemonConfigService $daemonConfigService;
	private IConfig $config;
	private DockerActions $dockerActions;
	private ExAppFetcher $exAppFetcher;
	private AppAPIService $service;
	private LoggerInterface $logger;

	public function __construct(
		IInitialState $initialStateService,
		DaemonConfigService $daemonConfigService,
		IConfig $config,
		DockerActions $dockerActions,
		ExAppFetcher $exAppFetcher,
		AppAPIService $service,
		LoggerInterface $logger,
	) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
		$this->exAppFetcher = $exAppFetcher;
		$this->service = $service;
		$this->logger = $logger;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$exApps = $this->service->getExAppsList('all');
		$daemonsExAppsCount = [];
		foreach ($exApps as $app) {
			$exApp = $this->service->getExApp($app['id']);
			if (!isset($daemonsExAppsCount[$exApp->getDaemonConfigName()])) {
				$daemonsExAppsCount[$exApp->getDaemonConfigName()] = 0;
			}
			$daemonsExAppsCount[$exApp->getDaemonConfigName()] += 1;
		}
		$daemons = array_map(function (DaemonConfig $daemonConfig) use ($daemonsExAppsCount) {
			return [
				...$daemonConfig->jsonSerialize(),
				'exAppsCount' => isset($daemonsExAppsCount[$daemonConfig->getName()]) ? $daemonsExAppsCount[$daemonConfig->getName()] : 0,
			];
		}, $this->daemonConfigService->getRegisteredDaemonConfigs());
		$adminInitialData = [
			'daemons' => $daemons,
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', ''),
			'updates_count' => count($this->getExAppsWithUpdates()),
		];

		$defaultDaemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', '');
		if ($defaultDaemonConfigName !== '') {
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($defaultDaemonConfigName);
			if ($daemonConfig !== null) {
				$this->dockerActions->initGuzzleClient($daemonConfig);
				$daemonConfigAccessible = $this->dockerActions->ping($this->dockerActions->buildDockerUrl($daemonConfig));
				$adminInitialData['daemon_config_accessible'] = $daemonConfigAccessible;
				if (!$daemonConfigAccessible) {
					$this->logger->error(sprintf('Deploy daemon "%s" is not accessible by Nextcloud. Please verify its configuration', $daemonConfig->getName()));
				}
			}
		}

		$this->initialStateService->provideInitialState('admin-initial-data', $adminInitialData);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}

	private function getExAppsWithUpdates(): array {
		$apps = $this->exAppFetcher->get();
		$appsWithUpdates = array_filter($apps, function (array $app) {
			$exApp = $this->service->getExApp($app['id']);
			$newestVersion = $app['releases'][0]['version'];
			return $exApp !== null && isset($app['releases'][0]['version']) && version_compare($newestVersion, $exApp->getVersion(), '>');
		});
		return array_values($appsWithUpdates);
	}
}
