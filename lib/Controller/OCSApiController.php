<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppApiScopeService;
use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;

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
use Psr\Log\LoggerInterface;

class OCSApiController extends OCSController {
	private LoggerInterface $logger;
	private AppEcosystemV2Service $service;
	private ExFilesActionsMenuService $exFilesActionsMenuService;
	private ExAppApiScopeService $exAppApiScopeService;
	private ?string $userId;
	protected $request;

	public function __construct(
		IRequest $request,
		?string $userId,
		AppEcosystemV2Service $service,
		ExFilesActionsMenuService $exFilesActionsMenuService,
		LoggerInterface $logger,
		ExAppApiScopeService $exAppApiScopeService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->logger = $logger;
		$this->service = $service;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
		$this->userId = $userId;
		$this->exAppApiScopeService = $exAppApiScopeService;
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $level
	 * @param string $message
	 *
	 * @throws OCSBadRequestException
	 * @return DataResponse
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function log(int $level, string $message): DataResponse {
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
			return new DataResponse();
		} catch (\Psr\Log\InvalidArgumentException) {
			$this->logger->error('Invalid log level');
			throw new OCSBadRequestException('Invalid log level');
		}
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

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return DataResponse
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppUsers(): DataResponse {
		return new DataResponse($this->service->getNCUsersList(), Http::STATUS_OK);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $apiRoute
	 * @param int $scopeGroup
	 * @param string $name
	 *
	 * @throws OCSBadRequestException
	 * @return DataResponse
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerApiScope(string $apiRoute, int $scopeGroup, string $name): DataResponse {
		$apiScope = $this->exAppApiScopeService->registerApiScope($apiRoute, $scopeGroup, $name);
		if ($apiScope === null) {
			throw new OCSBadRequestException('Failed to register API scope');
		}
		return new DataResponse(1, Http::STATUS_OK);
	}
}
