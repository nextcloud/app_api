<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;

use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;

/**
 * DaemonConfig actions (for UI)
 */
class DaemonConfigController extends ApiController {
	private DaemonConfigService $daemonConfigService;
	private IConfig $config;

	public function __construct(
		IRequest $request,
		IConfig $config,
		DaemonConfigService $daemonConfigService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->daemonConfigService = $daemonConfigService;
		$this->config = $config;
	}

	/**
	 * @NoCSRFRequired
	 */
	#[NoCSRFRequired]
	public function getAllDaemonConfigs(): Response {
		$daemonConfigs = $this->daemonConfigService->getRegisteredDaemonConfigs();
		return new JSONResponse([
			'daemons' => $daemonConfigs,
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', null),
		]);
	}

	/**
	 * @NoCSRFRequired
	 */
	#[NoCSRFRequired]
	public function registerDaemonConfig(array $daemonConfigParams): Response {
		$daemonConfig = $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
		return new JSONResponse([
			'success' => $daemonConfig !== null,
			'daemonConfig' => $daemonConfig,
		]);
	}

	/**
	 * @NoCSRFRequired
	 */
	#[NoCSRFRequired]
	public function updateDaemonConfig(string $name, array $params): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		$updatedDaemonConfig = new DaemonConfig($params);
		$updatedDaemonConfig->setId($daemonConfig->getId());
		$updatedDaemonConfig = $this->daemonConfigService->updateDaemonConfig($daemonConfig);
		return new JSONResponse([
			'success' => $updatedDaemonConfig !== null,
			'daemonConfig' => $updatedDaemonConfig,
		]);
	}

	/**
	 * @NoCSRFRequired
	 */
	#[NoCSRFRequired]
	public function unregisterDaemonConfig(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		$defaultDaemonConfig = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', null);
		if ($daemonConfig->getName() === $defaultDaemonConfig) {
			$this->config->deleteAppValue(Application::APP_ID, 'default_daemon_config');
		}
		$daemonConfig = $this->daemonConfigService->unregisterDaemonConfig($daemonConfig);
		return new JSONResponse([
			'success' => $daemonConfig !== null,
			'daemonConfig' => $daemonConfig,
		]);
	}

	/**
	 * @NoCSRFRequired
	 */
	#[NoCSRFRequired]
	public function verifyDaemonConnection(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		return new JSONResponse([
			'success' => $daemonConfig !== null,
		]);
	}
}
