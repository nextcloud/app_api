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

use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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
	private LoggerInterface $logger;
	private IL10N $l;
	private AppEcosystemV2Service $service;
	private ExFilesActionsMenuService $exFilesActionsMenuService;
	private ?string $userId;
	protected $request;

	public function __construct(
		IRequest $request,
		?string $userId,
		IL10N $l,
		AppEcosystemV2Service $service,
		ExFilesActionsMenuService $exFilesActionsMenuService,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->logger = $logger;
		$this->l = $l;
		$this->service = $service;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
		$this->userId = $userId;
	}

	/**
	 * @param int $level
	 * @param string $message
	 * @param string $format
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function log(
		int $level,
		string $message,
		string $format = 'json',
	): Response {
		try {
			$appId = $this->request->getHeader('EX-APP-ID');
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
			$this->logger->error('Invalid log level');
			throw new OCSBadRequestException('Invalid log level');
		}
	}

	/**
	 * @param string $appId
	 * @param array $appData
	 * @param string $format
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	public function registerExternalApp(string $appId, array $appData, string $format = 'json'): Response {
		$result = $this->service->registerExApp($appId, $appData);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'registeredExApp' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
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
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
	 * @param string $appId
	 * @param array $fileActionMenuParams [name, display_name, mime, permissions, order, icon, icon_class, action_handler]
	 * @param string $format
	 *
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenu(string $appId, array $fileActionMenuParams, string $format = 'json'): Response {
		$registeredFileActionMenu = $this->exFilesActionsMenuService->registerFileActionMenu($appId, $fileActionMenuParams);
		return $this->buildResponse(new DataResponse([
			'success' => $registeredFileActionMenu !== null,
			'registeredFileActionMenu' => $registeredFileActionMenu,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @param string $appId
	 * @param string $fileActionMenuName
	 * @param string $format
	 *
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName, string $format = 'json'): Response {
		$unregisteredFileActionMenu = $this->exFilesActionsMenuService->unregisterFileActionMenu($appId, $fileActionMenuName);
		return $this->buildResponse(new DataResponse([
			'success' => $unregisteredFileActionMenu !== null,
			'unregisteredFileActionMenu' => $unregisteredFileActionMenu,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @param string $appId
	 * @param string $actionName
	 * @param array $actionFile
	 * @param string $actionHandler
	 * @param string $format
	 * @return Response
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handleFileAction(string $appId, string $actionName, array $actionFile, string $actionHandler, string $format = 'json'): Response {
		$result = $this->exFilesActionsMenuService->handleFileAction($this->userId, $appId, $actionName, $actionHandler, $actionFile);
		return $this->buildResponse(new DataResponse([
			'success' => $result,
			'handleFileActionSent' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @param string $appId
	 * @param string $exFileActionName
	 *
	 * @return DataDisplayResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
	 * @param string|null $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppUsers(?string $appId = null, string $format = 'json'): Response {
		return $this->buildResponse(new DataResponse($this->service->getNCUsersList($appId), Http::STATUS_OK), $format);
	}
}
