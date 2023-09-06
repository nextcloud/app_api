<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppEcosystemAuth;
use OCA\AppAPI\Service\ExFilesActionsMenuService;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class ExFileActionsMenuController extends OCSController {
	private ExFilesActionsMenuService $exFilesActionsMenuService;
	private ?string $userId;
	protected $request;

	public function __construct(
		IRequest $request,
		?string $userId,
		ExFilesActionsMenuService $exFilesActionsMenuService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
		$this->userId = $userId;
	}


	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $fileActionMenuParams [name, display_name, mime, permissions, order, icon, icon_class, action_handler]
	 *
	 * @return DataResponse
	 */
	#[AppEcosystemAuth]
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
	#[AppEcosystemAuth]
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
