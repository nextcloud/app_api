<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\ProxyResponse;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppInitialStateService;
use OCA\AppAPI\Service\ExAppMenuEntryService;
use OCA\AppAPI\Service\ExAppScriptsService;
use OCA\AppAPI\Service\ExAppStylesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http as HttpAlias;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\Exception;
use OCP\Files\IMimeTypeDetector;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class MenuEntryController extends Controller {

	public bool $postprocess = false;
	public array $jsProxyMap = [];

	public function __construct(
		IRequest                                  $request,
		private IInitialState                     $initialState,
		private IURLGenerator                     $url,
		private ExAppMenuEntryService             $menuEntryService,
		private ExAppInitialStateService          $initialStateService,
		private ExAppScriptsService				  $scriptsService,
		private ExAppStylesService				  $stylesService,
		private AppAPIService                     $service,
		private IMimeTypeDetector                 $mimeTypeHelper,
		private ContentSecurityPolicyNonceManager $nonceManager,
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
		$response = new TemplateResponse(Application::APP_ID, 'main');
		return $response;
	}

	private function createProxyResponse(string $path, IResponse $response, $cache = true): ProxyResponse {
		$content = $response->getBody();
		$isHTML = pathinfo($path, PATHINFO_EXTENSION) === 'html';
		if ($isHTML) {
			$nonce = $this->nonceManager->getNonce();
			$content = str_replace(
				'<script',
				"<script nonce=\"$nonce\"",
				$content
			);
		}

		$mime = $response->getHeader('content-type');
		if (empty($mime)) {
			$mime = $this->mimeTypeHelper->detectPath($path);
			if (pathinfo($path, PATHINFO_EXTENSION) === 'wasm') {
				$mime = 'application/wasm';
			}
		}

		$proxyResponse = new ProxyResponse(
			data: $content,
			length: strlen($content),
			mimeType: $mime,
		);

		$headersToCopy = ['Content-Disposition', 'Last-Modified', 'Etag'];
		foreach ($headersToCopy as $element) {
			$headerValue = $response->getHeader($element);
			if (empty($headerValue)) {
				$proxyResponse->addHeader($element, $headerValue);
			}
		}

		if ($cache && !$isHTML) {
			$proxyResponse->cacheFor(3600);
		}

		$csp = new ContentSecurityPolicy();
		$csp->addAllowedScriptDomain($this->request->getServerHost());
		$csp->addAllowedScriptDomain('\'unsafe-eval\'');
		$csp->addAllowedScriptDomain('\'unsafe-inline\'');
		$csp->addAllowedFrameDomain($this->request->getServerHost());
		$proxyResponse->setContentSecurityPolicy($csp);
		return $proxyResponse;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppProxyGet(string $appId, string $other): Response {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp(
			$exApp, '/' . $other, $this->userId, 'GET', request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppProxyPost(string $appId, string $other): Response {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp(
			$exApp, '/' . $other, $this->userId,
			params: $this->request->getParams(),
			request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppProxyPut(string $appId, string $other): Response {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp(
			$exApp, '/' . $other, $this->userId, 'PUT',
			$this->request->getParams(),
			request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
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
