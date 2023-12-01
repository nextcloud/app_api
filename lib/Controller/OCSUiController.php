<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\ExFilesActionsMenuService;

use OCA\AppAPI\Service\TopMenuService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class OCSUiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ExFilesActionsMenuService $exFilesActionsMenuService,
		private TopMenuService           $menuEntryService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $fileActionMenuParams [name, display_name, mime, permissions, order, icon, icon_class, action_handler]
	 *
	 * @return DataResponse
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenu(array $fileActionMenuParams): DataResponse {
		$appId = $this->request->getHeader('EX-APP-ID');
		$registeredFileActionMenu = $this->exFilesActionsMenuService->registerFileActionMenu($appId, $fileActionMenuParams);
		return new DataResponse([
			'success' => $registeredFileActionMenu !== null,
			'registeredFileActionMenu' => $registeredFileActionMenu,
		], Http::STATUS_OK);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileActionMenuName
	 *
	 * @throws OCSNotFoundException
	 * @return DataResponse
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterFileActionMenu(string $fileActionMenuName): DataResponse {
		$appId = $this->request->getHeader('EX-APP-ID');
		$unregisteredFileActionMenu = $this->exFilesActionsMenuService->unregisterFileActionMenu($appId, $fileActionMenuName);
		if ($unregisteredFileActionMenu === null) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSBadRequestException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function registerExAppMenuEntry(
		string $name, string $displayName,
		string $iconUrl = '', int $adminRequired = 0): DataResponse {
		$result = $this->menuEntryService->registerExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $iconUrl, $adminRequired);
		if (!$result) {
			throw new OCSBadRequestException("Top Menu entry could not be registered");
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function unregisterExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->unregisterExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function getExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->getExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $appId
	 * @param string $actionName
	 * @param array $actionFile
	 * @param string $actionHandler
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handleFileAction(string $appId, string $actionName, array $actionFile, string $actionHandler): DataResponse {
		$result = $this->exFilesActionsMenuService->handleFileAction($this->userId, $appId, $actionName, $actionHandler, $actionFile);
		return new DataResponse([
			'success' => $result,
			'handleFileActionSent' => $result,
		], Http::STATUS_OK);
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
			$response->cacheFor(ExFilesActionsMenuService::ICON_CACHE_TTL, false, true);
			return $response;
		}
		return new DataDisplayResponse('', 400);
	}
}
