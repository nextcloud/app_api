<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\UI\InitialStateService;
use OCA\AppAPI\Service\UI\ScriptsService;
use OCA\AppAPI\Service\UI\StylesService;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\Exception;
use OCP\IGroupManager;
use OCP\IRequest;

class TopMenuController extends Controller {

	public bool $postprocess = false;
	public array $jsProxyMap = [];

	public function __construct(
		IRequest                             $request,
		private readonly IInitialState       $initialState,
		private readonly TopMenuService      $menuEntryService,
		private readonly InitialStateService $initialStateService,
		private readonly ScriptsService      $scriptsService,
		private readonly StylesService       $stylesService,
		private readonly ExAppService        $service,
		private readonly ?string             $userId,
		private readonly IGroupManager       $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function viewExAppPage(string $appId, string $name, string $other): TemplateResponse {
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
		if (filter_var($menuEntry->getAdminRequired(), FILTER_VALIDATE_BOOLEAN) && !$this->groupManager->isAdmin($this->userId)) {
			return new NotFoundResponse();
		}
		$initialStates = $this->initialStateService->getExAppInitialStates($appId, 'top_menu', $menuEntry->getName());
		foreach ($initialStates as $key => $value) {
			$this->initialState->provideInitialState($key, $value);
		}
		$this->jsProxyMap = $this->scriptsService->applyExAppScripts($appId, 'top_menu', $menuEntry->getName());
		$this->stylesService->applyExAppStyles($appId, 'top_menu', $menuEntry->getName());

		$this->postprocess = true;
		$response = new TemplateResponse(Application::APP_ID, 'embedded');
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedScriptDomain($this->request->getServerHost());
		$csp->addAllowedScriptDomain('\'unsafe-eval\'');
		$csp->addAllowedScriptDomain('\'unsafe-inline\'');
		$csp->addAllowedFrameDomain($this->request->getServerHost());
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
