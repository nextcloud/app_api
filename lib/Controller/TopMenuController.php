<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppInitialStateService;
use OCA\AppAPI\Service\ExAppScriptsService;
use OCA\AppAPI\Service\ExAppStylesService;
use OCA\AppAPI\Service\ExAppUsersService;
use OCA\AppAPI\Service\TopMenuService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\TemplateResponse;
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
		private ExAppUsersService		 $exAppUsersService,
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
		$this->exAppUsersService->setupExAppUser($exApp, $this->userId);
		return new TemplateResponse(Application::APP_ID, 'embedded');
	}
}
