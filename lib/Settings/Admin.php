<?php

declare(strict_types=1);

namespace OCA\AppAPI\Settings;

use OCA\AppAPI\AppInfo\Application;

use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	private IInitialState $initialStateService;
	private DaemonConfigService $daemonConfigService;
	private IConfig $config;
	private DockerActions $dockerActions;
	private ExAppFetcher $exAppFetcher;
	private AppAPIService $service;

	public function __construct(
		IInitialState $initialStateService,
		DaemonConfigService $daemonConfigService,
		IConfig $config,
		DockerActions $dockerActions,
		ExAppFetcher $exAppFetcher,
		AppAPIService $service,
	) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
		$this->exAppFetcher = $exAppFetcher;
		$this->service = $service;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$adminConfig = [
			'daemons' => $this->daemonConfigService->getRegisteredDaemonConfigs(),
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', ''),
			'docker_socket_accessible' => $this->dockerActions->isDockerSocketAvailable(),
			'updates_count' => count($this->getExAppsWithUpdates()),
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
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
