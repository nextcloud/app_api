<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\RequestOptions;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExAppRouteAccessLevel;
use OCA\AppAPI\ProxyResponse;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IMimeTypeDetector;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class ExAppProxyController extends Controller {

	public function __construct(
		IRequest                                           $request,
		private readonly AppAPIService                     $service,
		private readonly ExAppService					   $exAppService,
		private readonly IMimeTypeDetector                 $mimeTypeHelper,
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
		private readonly ?string                           $userId,
		private readonly IGroupManager                     $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function createProxyResponse(string $path, IResponse $response, $cache = true): ProxyResponse {
		$headersToIgnore = ['aa-version', 'ex-app-id', 'authorization-app-api', 'ex-app-version', 'aa-request-id'];
		$responseHeaders = [];
		foreach ($response->getHeaders() as $key => $value) {
			if (!in_array(strtolower($key), $headersToIgnore)) {
				$responseHeaders[$key] = $value[0];
			}
		}
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

		if (empty($response->getHeader('content-type'))) {
			$mime = $this->mimeTypeHelper->detectPath($path);
			if (pathinfo($path, PATHINFO_EXTENSION) === 'wasm') {
				$mime = 'application/wasm';
			}
			if (!empty($mime) && $mime != 'application/octet-stream') {
				$responseHeaders['Content-Type'] = $mime;
			}
		}

		$proxyResponse = new ProxyResponse($response->getStatusCode(), $responseHeaders, $content);
		if ($cache && !$isHTML && empty($response->getHeader('cache-control'))
			&& $response->getHeader('Content-Type') !== 'application/json'
			&& $response->getHeader('Content-Type') !== 'application/x-tar') {
			$proxyResponse->cacheFor(3600);
		}
		return $proxyResponse;
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppGet(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled() || !$this->passesExAppProxyRoutesChecks($appId, $other)) {
			return new NotFoundResponse();
		}

		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId, 'GET', queryParams: $_GET, options: [
				RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			],
			request: $this->request,
		);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPost(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled() || !$this->passesExAppProxyRoutesChecks($appId, $other)) {
			return new NotFoundResponse();
		}

		$options = [
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			'headers' => getallheaders(),
		];
		if (str_starts_with($this->request->getHeader('Content-Type'), 'multipart/form-data') || count($_FILES) > 0) {
			unset($options['headers']['Content-Type']);
			unset($options['headers']['Content-Length']);
			$options[RequestOptions::MULTIPART] = $this->buildMultipartFormData($_POST, $_FILES);
		} else {
			$options['body'] = $stream = fopen('php://input', 'r');
		}

		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId,
			queryParams: $_GET, options: $options, request: $this->request,
		);

		if (isset($stream) && is_resource($stream)) {
			fclose($stream);
		}
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPut(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled() || !$this->passesExAppProxyRoutesChecks($appId, $other)) {
			return new NotFoundResponse();
		}

		$stream = fopen('php://input', 'r');
		$options = [
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			'body' => $stream,
			'headers' => getallheaders(),
		];
		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId, 'PUT',
			queryParams: $_GET, options: $options, request: $this->request,
		);
		fclose($stream);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppDelete(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled() || !$this->passesExAppProxyRoutesChecks($appId, $other)) {
			return new NotFoundResponse();
		}

		$stream = fopen('php://input', 'r');
		$options = [
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			'body' => $stream,
			'headers' => getallheaders(),
		];
		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId, 'DELETE',
			queryParams: $_GET, options: $options, request: $this->request,
		);
		fclose($stream);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	private function buildProxyCookiesJar(array $cookies, string $domain): CookieJar {
		$cookieJar = new CookieJar();
		foreach ($cookies as $name => $value) {
			$cookieJar->setCookie(new SetCookie([
				'Domain' => $domain,
				'Name' => $name,
				'Value' => $value,
				'Discard' => true,
				'Secure' => false,
				'HttpOnly' => true,
			]));
		}
		return $cookieJar;
	}

	/**
	 * Build the multipart form data from input parameters and files
	 */
	private function buildMultipartFormData(array $bodyParams, array $files): array {
		$multipart = [];
		foreach ($bodyParams as $key => $value) {
			$multipart[] = [
				'name' => $key,
				'contents' => $value,
			];
		}
		foreach ($files as $key => $file) {
			$multipart[] = [
				'name' => $key,
				'contents' => fopen($file['tmp_name'], 'r'),
				'filename' => $file['name'],
			];
		}
		return $multipart;
	}

	private function passesExAppProxyRoutesChecks(string $appId, string $other): bool {
		$routes = $this->exAppService->getExApp($appId)->getRoutes();
		foreach ($routes as $route) {
			// check if the $route['url'] is regex and matches the $other route
			if (preg_match($route['url'], $other) === 1
				&& strtolower($route['verb']) === strtolower($this->request->getMethod())) {
				return $this->passesExAppProxyRouteAccessLevelCheck($route['access_level']);
			}
		}
		return false;
	}

	private function passesExAppProxyRouteAccessLevelCheck(string $accessLevel): bool {
		return match ($accessLevel) {
			ExAppRouteAccessLevel::ADMIN => $this->userId !== null && $this->groupManager->isAdmin($this->userId),
			ExAppRouteAccessLevel::USER => $this->userId !== null,
			ExAppRouteAccessLevel::PUBLIC => true,
			default => false,
		};
	}

	private function buildHeadersToInclude(ExApp $exApp): array {
		$headersToInclude = [];
		foreach ($exApp->getRoutes() as $route) {
			if (preg_match($route['url'], $other) === 1) {
				$headersToInclude = json_decode($route['headers_to_include'], true);
			}
		}
		return $headersToInclude;
	}
}
