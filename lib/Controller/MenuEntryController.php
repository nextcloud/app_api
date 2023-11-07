<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OC\AppFramework\Http;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\MenuEntryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;

class MenuEntryController extends Controller {
	private IInitialState $initialState;
	private IURLGenerator $url;
	private MenuEntryService $menuEntryService;
	private AppAPIService $service;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		IInitialState $initialState,
		IURLGenerator $url,
		MenuEntryService $menuEntryService,
		AppAPIService $service,
		?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->initialState = $initialState;
		$this->url = $url;
		$this->menuEntryService = $menuEntryService;
		$this->service = $service;
		$this->userId = $userId;
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function viewExAppPage(string $appId, string $name): TemplateResponse {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse(404);
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse(404);
		}
		$menuEntry = $this->menuEntryService->getExAppMenuEntry($appId, $name);
		if ($menuEntry === null) {
			return new NotFoundResponse(404);
		}
		$initialState = [
			'appid' => $appId,
			'iframe_url' => $this->url->linkToRouteAbsolute('app_api.MenuEntry.ExAppIframeProxy', ['appId' => $menuEntry->getAppid(), 'name' => $menuEntry->getName()]),
			'icon' => $this->url->linkToRouteAbsolute('app_api.MenuEntry.ExAppIconProxy', ['appId' => $menuEntry->getAppid(), 'name' => $menuEntry->getName()])
		];
		$this->initialState->provideInitialState('iframe-target', $initialState);

		$response = new TemplateResponse(Application::APP_ID, 'main');
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain($exApp->getAppid());
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppIframeProxy(string $appId, string $name): Response {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse(404);
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse(404);
		}
		$menuEntry = $this->menuEntryService->getExAppMenuEntry($appId, $name);
		if ($menuEntry === null) {
			return new NotFoundResponse(404);
		}
		$response = $this->service->aeRequestToExApp($this->request, $this->userId, $exApp, $menuEntry->getRoute(), 'GET');
		return new DataResponse($response);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppIconProxy(string $appId, string $name): Response {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse(404);
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse(404);
		}
		$icon = $this->menuEntryService->loadFileActionIcon($appId, $name, $exApp, $this->request, $this->userId);
		if ($icon !== null && isset($icon['body'], $icon['headers'])) {
			$response = new DataDisplayResponse(
				$icon['body'],
				Http::STATUS_OK,
				['Content-Type' => $icon['headers']['Content-Type'][0] ?? 'image/svg+xml']
			);
			$response->cacheFor(MenuEntryService::ICON_CACHE_TTL, false, true);
			return $response;
		}
		return new DataDisplayResponse('', 400);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function registerExAppMenuEntry(string $name, string $displayName, string $route, string $iconUrl = '', int $adminRequired = 0): DataResponse {
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function unregisterExAppMenuEntry(string $name): DataResponse {
		return new DataResponse();
	}
}
