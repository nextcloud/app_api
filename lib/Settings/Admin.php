<?php

declare(strict_types=1);

namespace OCA\AppAPI\Settings;

use OCA\AppAPI\AppInfo\Application;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use Psr\Log\LoggerInterface;

class Admin implements ISettings {

	public function __construct(
		private readonly IInitialState       $initialStateService,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly IConfig             $config,
		private readonly DockerActions       $dockerActions,
		private readonly ExAppService        $service,
		private readonly LoggerInterface     $logger,
	) {
	}

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
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config'),
		];

		$defaultDaemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config');
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
}
