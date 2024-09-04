<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * DaemonConfig actions (for UI)
 */
class DaemonConfigController extends ApiController {

	public function __construct(
		IRequest                             $request,
		private readonly IConfig             $config,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly DockerActions       $dockerActions,
		private readonly AppAPIService       $service,
		private readonly ExAppService        $exAppService,
		private readonly IL10N               $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function getAllDaemonConfigs(): Response {
		return new JSONResponse([
			'daemons' => $this->daemonConfigService->getDaemonConfigsWithAppsCount(),
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config'),
		]);
	}

	#[PasswordConfirmationRequired]
	public function registerDaemonConfig(array $daemonConfigParams, bool $defaultDaemon = false): Response {
		$daemonConfig = $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
		if ($daemonConfig !== null && $defaultDaemon) {
			$this->config->setAppValue(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName());
		}
		return new JSONResponse([
			'success' => $daemonConfig !== null,
			'daemonConfig' => $daemonConfig,
		]);
	}

	#[PasswordConfirmationRequired]
	public function updateDaemonConfig(string $name, array $daemonConfigParams): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		$updatedDaemonConfig = new DaemonConfig($daemonConfigParams);
		$updatedDaemonConfig->setId($daemonConfig->getId());
		$updatedDaemonConfig = $this->daemonConfigService->updateDaemonConfig($updatedDaemonConfig);
		return new JSONResponse([
			'success' => $updatedDaemonConfig !== null,
			'daemonConfig' => $updatedDaemonConfig,
		]);
	}

	#[PasswordConfirmationRequired]
	public function unregisterDaemonConfig(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		$defaultDaemonConfig = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', '');
		$this->service->removeExAppsByDaemonConfigName($daemonConfig);
		if ($daemonConfig->getName() === $defaultDaemonConfig) {
			$this->config->deleteAppValue(Application::APP_ID, 'default_daemon_config');
		}
		$daemonConfig = $this->daemonConfigService->unregisterDaemonConfig($daemonConfig);
		return new JSONResponse([
			'success' => $daemonConfig !== null,
			'daemonConfig' => $daemonConfig,
		]);
	}

	public function verifyDaemonConnection(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		if ($daemonConfig->getAcceptsDeployId() !== $this->dockerActions->getAcceptsDeployId()) {
			return new JSONResponse([
				'error' => sprintf('Only "%s" is supported', $this->dockerActions->getAcceptsDeployId()),
			]);
		}
		$this->dockerActions->initGuzzleClient($daemonConfig);
		$dockerDaemonAccessible = $this->dockerActions->ping($this->dockerActions->buildDockerUrl($daemonConfig));
		return new JSONResponse([
			'success' => $dockerDaemonAccessible,
		]);
	}

	public function checkDaemonConnection(array $daemonParams): Response {
		$daemonConfig = new DaemonConfig([
			'name' => $daemonParams['name'],
			'display_name' => $daemonParams['display_name'],
			'accepts_deploy_id' => $daemonParams['accepts_deploy_id'],
			'protocol' => $daemonParams['protocol'],
			'host' => $daemonParams['host'],
			'deploy_config' => $daemonParams['deploy_config'],
		]);
		if ($daemonConfig->getAcceptsDeployId() !== $this->dockerActions->getAcceptsDeployId()) {
			return new JSONResponse([
				'error' => sprintf('Only "%s" is supported', $this->dockerActions->getAcceptsDeployId()),
			]);
		}
		$this->dockerActions->initGuzzleClient($daemonConfig);
		$dockerDaemonAccessible = $this->dockerActions->ping($this->dockerActions->buildDockerUrl($daemonConfig));
		return new JSONResponse([
			'success' => $dockerDaemonAccessible,
		]);
	}

	public function startTestDeploy(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		if (!$daemonConfig) {
			return new JSONResponse(['error' => $this->l10n->t('Daemon config not found')], Http::STATUS_NOT_FOUND);
		}

		if (!$this->service->runOccCommand(
			sprintf("app_api:app:register --silent %s %s --info-xml %s --test-deploy-mode",
				Application::TEST_DEPLOY_APPID, $daemonConfig->getName(), Application::TEST_DEPLOY_INFO_XML)
		)) {
			return new JSONResponse(['error' => $this->l10n->t('Error starting install of ExApp')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$elapsedTime = 0;
		while ($elapsedTime < 5000000 && !$this->exAppService->getExApp(Application::TEST_DEPLOY_APPID)) {
			usleep(150000); // 0.15
			$elapsedTime += 150000;
		}

		$exApp = $this->exAppService->getExApp(Application::TEST_DEPLOY_APPID);
		if ($exApp === null) {
			return new JSONResponse(['error' => $this->l10n->t('ExApp failed to register, check the NC logs')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([
			'status' => $exApp->getStatus(),
		]);
	}

	public function stopTestDeploy(string $name): Response {
		$exApp = $this->exAppService->getExApp(Application::TEST_DEPLOY_APPID);
		if ($exApp !== null) {
			$this->service->runOccCommand(sprintf("app_api:app:unregister --silent --force %s", Application::TEST_DEPLOY_APPID));
			$elapsedTime = 0;
			while ($elapsedTime < 5000000 && $this->exAppService->getExApp(Application::TEST_DEPLOY_APPID) !== null) {
				usleep(150000); // 0.15
				$elapsedTime += 150000;
			}
		}
		$exApp = $this->exAppService->getExApp(Application::TEST_DEPLOY_APPID);
		return new JSONResponse([
			'success' => $exApp === null,
		]);
	}

	public function getTestDeployStatus(string $name): Response {
		$exApp = $this->exAppService->getExApp(Application::TEST_DEPLOY_APPID);
		if (is_null($exApp) || $exApp->getDaemonConfigName() !== $name) {
			return new JSONResponse(['error' => $this->l10n->t('ExApp not found, failed to get status')], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($exApp->getStatus());
	}
}
