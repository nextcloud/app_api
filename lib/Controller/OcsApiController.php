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

use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;

class OCSApiController extends OCSController {
	/** @var LoggerInterface */
	private $logger;

	/** @var IL10N */
	private $l;

	/** @var AppEcosystemV2Service */
	private $service;

	/** @var ExFilesActionsMenuService */
	private $exFilesActionsMenuService;

	/** @var string */
	private $userId;

	public function __construct(
		IRequest $request,
		?string $userId,
		IL10N $l,
		AppEcosystemV2Service $service,
		ExFilesActionsMenuService $exFilesActionsMenuService,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->logger = $logger;
		$this->l = $l;
		$this->service = $service;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
		$this->userId = $userId;
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
			$exAppEnabled = $exApp->getEnabled();
			if ($exAppEnabled !== 1) {
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
	 * @param string $appId
	 *
	 * @return Response
	 */
	public function unregisterExternalApp(string $appId, string $format = 'json'): Response {
		$deletedExApp = $this->service->unregisterExApp($appId);
		if ($deletedExApp === null) {
			return $this->buildResponse(new DataResponse([
				'success' => false,
				'error' => $this->l->t('ExApp not found'),
			], Http::STATUS_NOT_FOUND), $format);
		}
		return $this->buildResponse(new DataResponse([
			'success' => $deletedExApp->getAppid() === $appId,
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
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param array $fileActionMenuParams [name, display_name, mime, permissions, order, icon, icon_class, action_handler]
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
		$result = $this->exFilesActionsMenuService->handleFileAction($this->userId, $appId, $actionName, $actionHandler, $actionFile);
		return $this->buildResponse(new DataResponse([
			'success' => $result,
			'handleFileActionSent' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $exFileActionName
	 *
	 * @return DataDisplayResponse
	 */
	public function loadFileActionIcon(string $appId, string $exFileActionName): DataDisplayResponse {
		$icon = $this->exFilesActionsMenuService->loadFileActionIcon($appId, $exFileActionName);
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
