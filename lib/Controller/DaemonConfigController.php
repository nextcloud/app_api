<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;

/**
 * DaemonConfig actions (for UI)
 */
class DaemonConfigController extends ApiController {

	public function __construct(
		IRequest                             $request,
		private readonly IAppConfig          $appConfig,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly DockerActions       $dockerActions,
		private readonly AppAPIService       $service,
		private readonly ExAppService        $exAppService,
		private readonly IL10N               $l10n,
		private readonly ICrypto			 $crypto,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function getAllDaemonConfigs(): Response {
		return new JSONResponse([
			'daemons' => $this->daemonConfigService->getDaemonConfigsWithAppsCount(),
			'default_daemon_config' => $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', lazy: true),
		]);
	}

	#[PasswordConfirmationRequired]
	public function registerDaemonConfig(array $daemonConfigParams, bool $defaultDaemon = false): Response {
		$daemonConfig = $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
		if ($daemonConfig !== null && $defaultDaemon) {
			$this->appConfig->setValueString(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName(), lazy: true);
		}
		return new JSONResponse([
			'success' => $daemonConfig !== null,
			'daemonConfig' => $daemonConfig,
		]);
	}

	#[PasswordConfirmationRequired]
	public function updateDaemonConfig(string $name, array $daemonConfigParams): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);

		// Safely check if "haproxy_password" exists before accessing it
		$haproxyPassword = $daemonConfigParams['deploy_config']['haproxy_password'] ?? null;

		// Restore the original password if "dummySecret123" is provided
		if ($haproxyPassword === 'dummySecret123') {
			$daemonConfigParams['deploy_config']['haproxy_password'] = $daemonConfig->getDeployConfig()['haproxy_password'] ?? "";
		} elseif (!empty($haproxyPassword)) {
			// New password provided, encrypt it
			$daemonConfigParams['deploy_config']['haproxy_password'] = $this->crypto->encrypt($haproxyPassword);
		}

		// Create and update DaemonConfig instance
		$updatedDaemonConfig = new DaemonConfig($daemonConfigParams);
		$updatedDaemonConfig->setId($daemonConfig->getId());
		$updatedDaemonConfig = $this->daemonConfigService->updateDaemonConfig($updatedDaemonConfig);

		// Check if update was successful before proceeding
		if ($updatedDaemonConfig === null) {
			return new JSONResponse([
				'success' => false,
				'daemonConfig' => null,
			]);
		}

		// Mask the password with "dummySecret123" if it is set
		$updatedDeployConfig = $updatedDaemonConfig->getDeployConfig();
		if (!empty($updatedDeployConfig['haproxy_password'] ?? null)) {
			$updatedDeployConfig['haproxy_password'] = 'dummySecret123';
			$updatedDaemonConfig->setDeployConfig($updatedDeployConfig);
		}

		return new JSONResponse([
			'success' => true,
			'daemonConfig' => $updatedDaemonConfig,
		]);
	}

	#[PasswordConfirmationRequired]
	public function unregisterDaemonConfig(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		$defaultDaemonConfig = $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', '', lazy: true);
		$this->service->removeExAppsByDaemonConfigName($daemonConfig);
		if ($daemonConfig->getName() === $defaultDaemonConfig) {
			$this->appConfig->deleteKey(Application::APP_ID, 'default_daemon_config');
		}
		$daemonConfig = $this->daemonConfigService->unregisterDaemonConfig($daemonConfig);
		return new JSONResponse([
			'success' => $daemonConfig !== null,
			'daemonConfig' => $daemonConfig,
		]);
	}

	public function verifyDaemonConnection(string $name): Response {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		$this->dockerActions->initGuzzleClient($daemonConfig);
		$dockerDaemonAccessible = $this->dockerActions->ping($this->dockerActions->buildDockerUrl($daemonConfig));
		return new JSONResponse([
			'success' => $dockerDaemonAccessible,
		]);
	}

	public function checkDaemonConnection(array $daemonParams): Response {
		// Safely check if "haproxy_password" exists before accessing it
		// note: UI passes here 'deploy_config' instead of 'deployConfig'
		$haproxyPassword = $daemonParams['deploy_config']['haproxy_password'] ?? null;

		if ($haproxyPassword === 'dummySecret123') {
			// For cases when the password itself is 'dummySecret123'
			$daemonParams['deploy_config']['haproxy_password'] = $this->crypto->encrypt($haproxyPassword);

			// Check if such record is present in the DB
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonParams['name']);
			if ($daemonConfig !== null) {
				// such Daemon config already present in the DB
				$haproxyPasswordDB = $daemonConfig->getDeployConfig()['haproxy_password'] ?? "";
				if ($haproxyPasswordDB) {
					// get password from the DB instead of the “masked” one
					$daemonParams['deploy_config']['haproxy_password'] = $haproxyPasswordDB;
				}
			}
		} elseif (!empty($haproxyPassword)) {
			// New password provided, encrypt it, as "initGuzzleClient" expects to receive encrypted password
			$daemonParams['deploy_config']['haproxy_password'] = $this->crypto->encrypt($haproxyPassword);
		}

		$daemonConfig = new DaemonConfig([
			'name' => $daemonParams['name'],
			'display_name' => $daemonParams['display_name'],
			'accepts_deploy_id' => $daemonParams['accepts_deploy_id'],
			'protocol' => $daemonParams['protocol'],
			'host' => $daemonParams['host'],
			'deploy_config' => $daemonParams['deploy_config'],
		]);
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

		$commandParts = [
			'app_api:app:register',
			'--silent',
			Application::TEST_DEPLOY_APPID,
			$daemonConfig->getName(),
			'--info-xml',
			Application::TEST_DEPLOY_INFO_XML,
			'--test-deploy-mode',
		];
		if (!$this->service->runOccCommand($commandParts)) {
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
			$commandParts = [
				'app_api:app:unregister',
				'--silent',
				'--force',
				Application::TEST_DEPLOY_APPID,
			];
			$this->service->runOccCommand($commandParts);
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

	#[PasswordConfirmationRequired]
	public function addDaemonDockerRegistry(string $name, array $registryMap): JSONResponse {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		if (!$daemonConfig) {
			return new JSONResponse(['error' => $this->l10n->t('Daemon config not found')], Http::STATUS_NOT_FOUND);
		}
		$daemonConfig = $this->daemonConfigService->addDockerRegistry($daemonConfig, $registryMap);
		if ($daemonConfig === null) {
			return new JSONResponse(['error' => $this->l10n->t('Error adding Docker registry')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($daemonConfig, Http::STATUS_OK);
	}

	#[PasswordConfirmationRequired]
	public function removeDaemonDockerRegistry(string $name, array $registryMap): JSONResponse {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		if (!$daemonConfig) {
			return new JSONResponse(['error' => $this->l10n->t('Daemon config not found')], Http::STATUS_NOT_FOUND);
		}
		$daemonConfig = $this->daemonConfigService->removeDockerRegistry($daemonConfig, $registryMap);
		if ($daemonConfig === null) {
			return new JSONResponse(['error' => $this->l10n->t('Error removing Docker registry')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($daemonConfig, Http::STATUS_OK);
	}
}
