<?php

declare(strict_types=1);

namespace OCA\AppAPI\Settings;

use OCA\AppAPI\AppInfo\Application;

use OCA\AppAPI\DeployActions\DockerActions;
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

	public function __construct(
		IInitialState $initialStateService,
		DaemonConfigService $daemonConfigService,
		IConfig $config,
		DockerActions $dockerActions,
	) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$adminConfig = [
			'daemons' => $this->daemonConfigService->getRegisteredDaemonConfigs(),
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', null),
			'docker_socket_accessible' => $this->dockerActions->isDockerSocketAvailable(),
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
}
