<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppInitialStateService;
use OCA\AppAPI\Service\ExAppScriptsService;
use OCA\AppAPI\Service\ExAppStylesService;
use OCA\AppAPI\Service\TopMenuService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http as HttpAlias;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\Exception;
use OCP\IRequest;

class TopMenuController extends Controller {

	public bool $postprocess = false;
	public array $jsProxyMap = [];

	public function __construct(
		IRequest                         $request,
		private IInitialState            $initialState,
		private TopMenuService           $menuEntryService,
		private ExAppInitialStateService $initialStateService,
		private ExAppScriptsService      $scriptsService,
		private ExAppStylesService       $stylesService,
		private AppAPIService            $service,
		private ?string                  $userId,
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
		$initialStates = $this->initialStateService->getExAppInitialStates($appId, 'top_menu', $menuEntry->getName());
		foreach ($initialStates as $key => $value) {
			$this->initialState->provideInitialState($key, $value);
		}
		$this->jsProxyMap = $this->scriptsService->applyExAppScripts($appId, 'top_menu', $menuEntry->getName());
		$this->stylesService->applyExAppStyles($appId, 'top_menu', $menuEntry->getName());

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
			$response->cacheFor(TopMenuService::ICON_CACHE_TTL, false, true);
			return $response;
		}
		return new DataDisplayResponse('', 400);
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
}
