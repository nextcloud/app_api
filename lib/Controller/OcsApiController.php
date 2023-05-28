<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Controller;

use Psr\Log\LoggerInterface;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppConfigService;
use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IL10N;
use OCP\IRequest;

class OCSApiController extends OCSController {
	/** @var LoggerInterface */
	private $logger;

	/** @var IL10N */
	private $l;

	/** @var AppEcosystemV2Service */
	private $service;

	/** @var ExAppConfigService */
	private $exAppConfigService;

	/** @var ExFilesActionsMenuService */
	private $exFilesActionsMenuService;

	public function __construct(
		IRequest $request,
		IL10N $l,
		AppEcosystemV2Service $service,
		ExAppConfigService $exAppConfigService,
		ExFilesActionsMenuService $exFilesActionsMenuService,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->logger = $logger;
		$this->l = $l;
		$this->service = $service;
		$this->exAppConfigService = $exAppConfigService;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * 
	 * @param string $appId
	 * @param int $level
	 * @param string $message
	 *
	 * @return DataResponse
	 * @throws OCSBadRequestException
	 */
	public function log(
		string $appId,
		int $level,
		string $message,
	): DataResponse {
		try {
			$exApp = $this->service->getExApp($appId);
			if ($exApp === null) {
				$this->logger->error('ExApp ' . $appId . ' not found');
				throw new OCSBadRequestException('ExApp not found');
			}
			$exAppConfigEnabled = $this->exAppConfigService->getAppConfigValue($appId, 'enabled', false);
			if (!$exAppConfigEnabled) {
				$this->logger->error('ExApp ' . $appId . ' is disabled');
				throw new OCSBadRequestException('ExApp is disabled');
			}
			$this->logger->log($level, $message, [
				'app' => $appId,
			]);
			return new DataResponse(1, Http::STATUS_OK);
		} catch (\Psr\Log\InvalidArgumentException) {
			$this->logger->error('Invalid log level: ' . $level, [
				'app' => $appId,
				'level' => $level,
			]);
			throw new OCSBadRequestException('Invalid log level');
		}
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $actionId
	 *
	 * @return JSONResponse
	 */
	public function registerExternalApp(string $appId, array $appData): JSONResponse {
		// TODO
		$result = $this->service->registerExApp($appId, $appData);
		return new JSONResponse([
			'success' => $result !== null,
			'registeredExApp' => $result,
		], Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $appid
	 *
	 * @return JSONResponse
	 */
	public function unregisterExternalApp(string $appid): JSONResponse {
		$deletedExApp = $this->service->unregisterExApp($appid);
		if ($deletedExApp === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l->t('ExApp not found'),
			], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([
			'success' => $deletedExApp->getAppid() === $appid,
			'deletedExApp' => $deletedExApp,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	public function getAppStatus(string $appId): JSONResponse {
		$appStatus = $this->service->getAppStatus($appId);
		return new JSONResponse([
			'success' => $appStatus !== null,
			'appStatus' => [
				'appId'=> $appId,
				'status' => $appStatus,
			],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param array $fileActionMenuParams
	 *
	 * @return JSONResponse
	 */
	public function registerFileActionMenu(string $appId, array $fileActionMenuParams): JSONResponse {
		$registeredFileActionMenu = $this->exFilesActionsMenuService->registerFileActionMenu($appId, $fileActionMenuParams);
		return new JSONResponse([
			'success' => $registeredFileActionMenu !== null,
			'registeredFileActionMenu' => $registeredFileActionMenu,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $fileActionMenuName
	 *
	 * @return JSONResponse
	 */
	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName): JSONResponse {
		$unregisteredFileActionMenu = $this->exFilesActionsMenuService->unregisterFileActionMenu($appId, $fileActionMenuName);
		return new JSONResponse([
			'success' => $unregisteredFileActionMenu !== null,
			'unregisteredFileActionMenu' => $unregisteredFileActionMenu,
		], Http::STATUS_OK);
	}

	public function handleFileAction(string $appId, array $actionFile): JSONResponse {
		// TODO
		return new JSONResponse([
			'success' => false,
			'appId' => $appId,
			'actionFile' => $actionFile,
			'error' => 'Not implemented',
		], Http::STATUS_NOT_IMPLEMENTED);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $configValue
	 *
	 * @return JSONResponse
	 */
	public function setAppConfigValue(string $appId, string $configKey, string $configValue): JSONResponse {
		$result = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $configValue);
		return new JSONResponse([
			'success' => $result !== null,
			'setAppConfigValue' => $result,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @return JSONResponse
	 */
	public function getAppConfigValue(string $appId, string $configKey): JSONResponse {
		$appConfigEx = $this->exAppConfigService->getAppConfigValue($appId, $configKey);
		return new JSONResponse([
			'success' => $appConfigEx !== null,
			'appConfigEx' => $appConfigEx,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	public function getAppConfigKeys(string $appId): JSONResponse {
		$appConfigExs = $this->exAppConfigService->getAppConfigKeys($appId);
		return new JSONResponse([
			'success' => $appConfigExs !== null,
			'appConfigExs' => $appConfigExs,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $configValue
	 *
	 * @return JSONResponse
	 */
	public function deleteAppConfigValue(string $appId, string $configKey): JSONResponse {
		$result = $this->exAppConfigService->deleteAppConfigValue($appId, $configKey);
		return new JSONResponse([
			'success' => $result !== null,
			'deletedAppConfigValue' => $result,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	public function deleteAppConfigValues(string $appId): JSONResponse {
		$result = $this->exAppConfigService->deleteAppConfigValues($appId);
		return new JSONResponse([
			'success' => $result !== null && $result > 0,
			'deletedAppConfigValuesCount' => $result,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $configValue
	 * 
	 * @return JSONResponse
	 */
	public function setUserConfigValue(string $userId, string $appId, string $configKey, string $configValue): JSONResponse {
		// TODO
		return new JSONResponse([
			'success' => true,
			'userConfigValue' => [],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @return JSONResponse
	 */
	public function getUserConfigValue(string $userId, string $appId, string $configKey): JSONResponse {
		// TODO
		return new JSONResponse([
			'success' => true,
			'userConfigValue' => [],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	public function getUserConfigKeys(string $userId, string $appId): JSONResponse {
		// TODO
		return new JSONResponse([
			'success' => true,
			'userConfigKeys' => [],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @return JSONResponse
	 */
	public function deleteUserConfigValue(string $userId, string $appId, string $configKey): JSONResponse {
		// TODO
		return new JSONResponse([
			'success' => true,
			'deletedUserConfigValues' => [],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	public function deleteUserConfigValues(string $userId, string $appId): JSONResponse {
		// TODO
		return new JSONResponse([
			'success' => true,
			'deletedUserConfigValues' => [],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function sendNotification() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerSearchProvider() {
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerBackgroundJob() {
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerSettingsPage() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerSettingsSection() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerEventListener() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerDashboardWidget() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerCapabilities() {
	}
}
