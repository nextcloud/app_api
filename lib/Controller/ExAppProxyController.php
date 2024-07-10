<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\RequestOptions;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\ProxyResponse;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IMimeTypeDetector;
use OCP\Http\Client\IResponse;
use OCP\IRequest;

class ExAppProxyController extends Controller {

	public function __construct(
		IRequest                                           $request,
		private readonly AppAPIService                     $service,
		private readonly ExAppService					   $exAppService,
		private readonly IMimeTypeDetector                 $mimeTypeHelper,
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
		private readonly ?string                           $userId,
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppGet(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp2(
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPost(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$options = [
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
		];
		if (str_starts_with($this->request->getHeader('Content-Type'), 'multipart/form-data') || count($_FILES) > 0) {
			$multipart = $this->buildMultipartFormData($this->prepareBodyParams($this->request->getParams()), $_FILES);
			$options[RequestOptions::MULTIPART] = $multipart;
		}
		$bodyParams = $this->prepareBodyParams($this->request->getParams());

		$response = $this->service->aeRequestToExApp2(
			$exApp, '/' . $other, $this->userId,
			queryParams: $_GET, bodyParams: $bodyParams, options: $options,
			request: $this->request,
		);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPut(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$options = [
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
		];
		if (str_starts_with($this->request->getHeader('Content-Type'), 'multipart/form-data') || count($_FILES) > 0) {
			$multipart = $this->buildMultipartFormData($this->prepareBodyParams($this->request->getParams()), $_FILES);
			$options[RequestOptions::MULTIPART] = $multipart;
		}
		$bodyParams = $this->prepareBodyParams($this->request->getParams());

		$response = $this->service->aeRequestToExApp2(
			$exApp, '/' . $other, $this->userId, 'PUT', queryParams: $_GET, bodyParams: $bodyParams,
			options: $options,
			request: $this->request,
		);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppDelete(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null || !$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$bodyParams = $this->prepareBodyParams($this->request->getParams());
		$response = $this->service->aeRequestToExApp2(
			$exApp, '/' . $other, $this->userId, 'DELETE', queryParams: $_GET, bodyParams: $bodyParams,
			options: [
				RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			],
			request: $this->request,
		);
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

	private function prepareBodyParams(array $params): array {
		unset($params['appId'], $params['other'], $params['_route']);
		foreach ($_GET as $key => $value) {
			unset($params[$key]);
		}
		return $params;
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
}
