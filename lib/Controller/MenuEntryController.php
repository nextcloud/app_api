<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppInitialStateService;
use OCA\AppAPI\Service\ExAppMenuEntryService;
use OCA\AppAPI\Service\ExAppScriptsService;
use OCA\AppAPI\Service\ExAppStylesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http as HttpAlias;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\Exception;
use OCP\IRequest;

class MenuEntryController extends Controller {

	public bool $postprocess = false;
	public array $jsProxyMap = [];

	public function __construct(
		IRequest                                  $request,
		private IInitialState                     $initialState,
		private ExAppMenuEntryService             $menuEntryService,
		private ExAppInitialStateService          $initialStateService,
		private ExAppScriptsService				  $scriptsService,
		private ExAppStylesService				  $stylesService,
		private AppAPIService                     $service,
		private ?string                           $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function viewExAppPage(string $appId, string $name): TemplateResponse {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}
		$menuEntry = $this->menuEntryService->getExAppMenuEntry($appId, $name);
		if ($menuEntry === null) {
			return new NotFoundResponse();
		}
		$initialStates = $this->initialStateService->getExAppInitialStates($appId, 'top_menu');
		foreach ($initialStates as $key => $value) {
			$this->initialState->provideInitialState($key, $value);
		}
		$this->jsProxyMap = $this->scriptsService->applyExAppScripts($appId, 'top_menu');
		$this->stylesService->applyExAppStyles($appId, 'top_menu');

		$this->postprocess = true;
		return new TemplateResponse(Application::APP_ID, 'embedded');
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
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}
		$icon = $this->menuEntryService->loadFileActionIcon($appId, $name, $exApp, $this->request, $this->userId);
		if ($icon !== null && isset($icon['body'], $icon['headers'])) {
			$response = new DataDisplayResponse(
				$icon['body'],
				HttpAlias::STATUS_OK,
				['Content-Type' => $icon['headers']['Content-Type'][0] ?? 'image/svg+xml']
			);
			$response->cacheFor(ExAppMenuEntryService::ICON_CACHE_TTL, false, true);
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
