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
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
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

	// TODO: Implement intermediate check for all routes 
	// (authorization, NC version, ExApp version, AppEcosystemVersion injection, etc.)

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * 
	 * @param string $appId
	 * @param int $level
	 * @param string $message
	 *
	 * @return Response
	 * @throws OCSBadRequestException
	 */
	public function log(
		string $appId,
		int $level,
		string $message,
		string $format = 'json',
	): Response {
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
			return $this->buildResponse(new DataResponse(1, Http::STATUS_OK), $format);
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
	 * @return Response
	 */
	public function registerExternalApp(string $appId, array $appData, string $format = 'json'): Response {
		$result = $this->service->registerExApp($appId, $appData);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'registeredExApp' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $appid
	 *
	 * @return Response
	 */
	public function unregisterExternalApp(string $appid, string $format = 'json'): Response {
		$deletedExApp = $this->service->unregisterExApp($appid);
		if ($deletedExApp === null) {
			return $this->buildResponse(new DataResponse([
				'success' => false,
				'error' => $this->l->t('ExApp not found'),
			], Http::STATUS_NOT_FOUND), $format);
		}
		return $this->buildResponse(new DataResponse([
			'success' => $deletedExApp->getAppid() === $appid,
			'deletedExApp' => $deletedExApp,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 *
	 * @return Response
	 */
	public function getAppStatus(string $appId, string $format = 'json'): Response {
		$appStatus = $this->service->getAppStatus($appId);
		return $this->buildResponse(new DataResponse([
			'success' => $appStatus !== null,
			'appStatus' => [
				'appId'=> $appId,
				'status' => $appStatus,
			],
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param array $fileActionMenuParams
	 *
	 * @return Response
	 */
	public function registerFileActionMenu(string $appId, array $fileActionMenuParams, string $format = 'json'): Response {
		$registeredFileActionMenu = $this->exFilesActionsMenuService->registerFileActionMenu($appId, $fileActionMenuParams);
		return $this->buildResponse(new DataResponse([
			'success' => $registeredFileActionMenu !== null,
			'registeredFileActionMenu' => $registeredFileActionMenu,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $fileActionMenuName
	 *
	 * @return Response
	 */
	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName, string $format = 'json'): Response {
		$unregisteredFileActionMenu = $this->exFilesActionsMenuService->unregisterFileActionMenu($appId, $fileActionMenuName);
		return $this->buildResponse(new DataResponse([
			'success' => $unregisteredFileActionMenu !== null,
			'unregisteredFileActionMenu' => $unregisteredFileActionMenu,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $actionName
	 * @param array $actionFile
	 *
	 * @return Response
	 */
	public function handleFileAction(string $appId, string $actionName, array $actionFile, string $actionHandler, string $format = 'json'): Response {
		$result = $this->exFilesActionsMenuService->handleFileAction($appId, $actionName, $actionHandler, $actionFile);
		return $this->buildResponse(new DataResponse([
			'success' => $result,
			'handleFileActionSent' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $url
	 *
	 * @return DataDisplayResponse
	 */
	public function loadFileActionIcon(string $url): DataDisplayResponse {
		$icon = $this->exFilesActionsMenuService->loadFileActionIcon($url);
		if ($icon !== null && isset($icon['body'], $icon['headers'])) {
			$response = new DataDisplayResponse(
				$icon['body'],
				Http::STATUS_OK,
				['Content-Type' => $icon['headers']['Content-Type'][0] ?? 'image/svg+xml']
			);
			$response->cacheFor(Application::ICON_CACHE_TTL, false, true);
			return $response;
		}
		return new DataDisplayResponse('', 400);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $configValue
	 *
	 * @return Response
	 */
	public function setAppConfigValue(string $appId, string $configKey, string $configValue, string $format = 'json'): Response {
		$result = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $configValue);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'setAppConfigValue' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getAppConfigValue(string $appId, string $configKey, string $format): Response {
		$appConfigEx = $this->exAppConfigService->getAppConfigValue($appId, $configKey);
		return $this->buildResponse(new DataResponse([
			'success' => $appConfigEx !== null,
			'appConfigEx' => $appConfigEx,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 *
	 * @return Response
	 */
	public function getAppConfigKeys(string $appId): Response {
		$appConfigExs = $this->exAppConfigService->getAppConfigKeys($appId);
		return new DataResponse([
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
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteAppConfigValue(string $appId, string $configKey, string $format = 'json'): Response {
		$result = $this->exAppConfigService->deleteAppConfigValue($appId, $configKey);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'deletedAppConfigValue' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteAppConfigValues(string $appId, string $format = 'json'): Response {
		$result = $this->exAppConfigService->deleteAppConfigValues($appId);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null && $result > 0,
			'deletedAppConfigValuesCount' => $result,
		], Http::STATUS_OK), $format);
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
