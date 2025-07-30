<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\RequestOptions;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
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
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

class ExAppProxyController extends Controller {

	public function __construct(
		IRequest                                           $request,
		private readonly AppAPIService                     $service,
		private readonly ExAppService					   $exAppService,
		private readonly IMimeTypeDetector                 $mimeTypeHelper,
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
		private readonly ?string                           $userId,
		private readonly IGroupManager                     $groupManager,
		private readonly LoggerInterface                   $logger,
		private readonly IThrottler              		   $throttler,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function createProxyResponse(string $path, IResponse $response, bool $isHTML, $cache = true): ProxyResponse {
		$headersToIgnore = ['aa-version', 'ex-app-id', 'authorization-app-api', 'ex-app-version', 'aa-request-id'];
		$responseHeaders = [];
		foreach ($response->getHeaders() as $key => $value) {
			if (!in_array(strtolower($key), $headersToIgnore)) {
				$responseHeaders[$key] = $value[0];
			}
		}
		$content = $response->getBody();

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

		if (isset($responseHeaders['Transfer-Encoding'])
			&& str_contains(strtolower($responseHeaders['Transfer-Encoding']), 'chunked')) {
			unset($responseHeaders['Transfer-Encoding']);
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
		$route = [];
		$bruteforceProtection = [];
		$delay = 0;
		$exApp = $this->prepareProxy($appId, $other, $route, $bruteforceProtection, $delay);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		$isHTML = pathinfo($other, PATHINFO_EXTENSION) === 'html';

		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId, 'GET', queryParams: $_GET, options: [
				'stream' => !$isHTML, // Can't stream HTML
				RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
				RequestOptions::HEADERS => $this->buildHeadersWithExclude($route, getallheaders()),
				RequestOptions::TIMEOUT => 0,
			],
			request: $this->request,
		);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}

		$this->processBruteforce($bruteforceProtection, $delay, $response->getStatusCode());
		return $this->createProxyResponse($other, $response, $isHTML);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPost(string $appId, string $other): Response {
		$route = [];
		$bruteforceProtection = [];
		$delay = 0;
		$exApp = $this->prepareProxy($appId, $other, $route, $bruteforceProtection, $delay);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		$isHTML = pathinfo($other, PATHINFO_EXTENSION) === 'html';

		$options = [
			'stream' => !$isHTML,
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			RequestOptions::HEADERS => $this->buildHeadersWithExclude($route, getallheaders()),
			RequestOptions::TIMEOUT => 0,
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

		$this->processBruteforce($bruteforceProtection, $delay, $response->getStatusCode());
		return $this->createProxyResponse($other, $response, $isHTML);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPut(string $appId, string $other): Response {
		$route = [];
		$bruteforceProtection = [];
		$delay = 0;
		$exApp = $this->prepareProxy($appId, $other, $route, $bruteforceProtection, $delay);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		$isHTML = pathinfo($other, PATHINFO_EXTENSION) === 'html';

		$stream = fopen('php://input', 'r');
		$options = [
			'stream' => !$isHTML,
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			RequestOptions::BODY => $stream,
			RequestOptions::HEADERS => $this->buildHeadersWithExclude($route, getallheaders()),
			RequestOptions::TIMEOUT => 0,
		];
		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId, 'PUT',
			queryParams: $_GET, options: $options, request: $this->request,
		);
		fclose($stream);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}

		$this->processBruteforce($bruteforceProtection, $delay, $response->getStatusCode());
		return $this->createProxyResponse($other, $response, $isHTML);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppDelete(string $appId, string $other): Response {
		$route = [];
		$bruteforceProtection = [];
		$delay = 0;
		$exApp = $this->prepareProxy($appId, $other, $route, $bruteforceProtection, $delay);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		$isHTML = pathinfo($other, PATHINFO_EXTENSION) === 'html';

		$stream = fopen('php://input', 'r');
		$options = [
			'stream' => !$isHTML,
			RequestOptions::COOKIES => $this->buildProxyCookiesJar($_COOKIE, $this->service->getExAppDomain($exApp)),
			RequestOptions::BODY => $stream,
			RequestOptions::HEADERS => $this->buildHeadersWithExclude($route, getallheaders()),
			RequestOptions::TIMEOUT => 0,
		];
		$response = $this->service->requestToExApp2(
			$exApp, '/' . $other, $this->userId, 'DELETE',
			queryParams: $_GET, options: $options, request: $this->request,
		);
		fclose($stream);
		if (is_array($response)) {
			return (new Response())->setStatus(500);
		}

		$this->processBruteforce($bruteforceProtection, $delay, $response->getStatusCode());
		return $this->createProxyResponse($other, $response, $isHTML);
	}

	private function prepareProxy(
		string $appId, string $other, array &$route, array &$bruteforceProtection, int &$delay
	): ?ExApp {
		$delay = 0;
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$this->logger->debug(
				sprintf('Returning status 404 for "%s": ExApp is not found.', $other)
			);
			return null;
		} elseif (!$exApp->getEnabled()) {
			$this->logger->debug(
				sprintf('Returning status 404 for "%s": ExApp is not enabled.', $other)
			);
			return null;
		}
		$route = $this->passesExAppProxyRoutesChecks($exApp, $other);
		if (empty($route)) {
			$this->logger->debug(
				sprintf('Returning status 404 for "%s": route does not pass the access check.', $other)
			);
			return null;
		}
		$bruteforceProtection = isset($route['bruteforce_protection'])
			? json_decode($route['bruteforce_protection'], true)
			: [];
		if (!empty($bruteforceProtection)) {
			$delay = $this->throttler->sleepDelayOrThrowOnMax($this->request->getRemoteAddress(), Application::APP_ID);
		}
		return $exApp;
	}

	private function processBruteforce(array $bruteforceProtection, int $delay, int $status): void {
		if (!empty($bruteforceProtection)) {
			if ($delay > 0 && ($status >= 200 && $status < 300)) {
				$this->throttler->resetDelay($this->request->getRemoteAddress(), Application::APP_ID, []);
			} elseif (in_array($status, $bruteforceProtection)) {
				$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress());
			}
		}
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

	private function passesExAppProxyRoutesChecks(ExApp $exApp, string $exAppRoute): array {
		foreach ($exApp->getRoutes() as $route) {
			if (preg_match('/' . $route['url'] . '/i', $exAppRoute) === 1 &&
				str_contains(strtolower($route['verb']), strtolower($this->request->getMethod())) &&
				$this->passesExAppProxyRouteAccessLevelCheck($route['access_level'])
			) {
				return $route;
			}
		}
		return [];
	}

	private function passesExAppProxyRouteAccessLevelCheck(int $accessLevel): bool {
		return match ($accessLevel) {
			ExAppRouteAccessLevel::PUBLIC->value => true,
			ExAppRouteAccessLevel::USER->value => $this->userId !== null,
			ExAppRouteAccessLevel::ADMIN->value => $this->userId !== null && $this->groupManager->isAdmin($this->userId),
			default => false,
		};
	}

	private function buildHeadersWithExclude(array $route, array $headers): array {
		$headersToExclude = json_decode($route['headers_to_exclude'], true);
		$headersToExclude = array_map('strtolower', $headersToExclude);

		if (!in_array('x-origin-ip', $headersToExclude)) {
			$headersToExclude[] = 'x-origin-ip';
		}
		if (!in_array('content-length', $headersToExclude)) {
			$headersToExclude[] = 'content-length';
		}
		$headersToExclude[] = 'authorization-app-api';
		foreach ($headers as $key => $value) {
			if (in_array(strtolower($key), $headersToExclude)) {
				unset($headers[$key]);
			}
		}
		$headers['x-origin-ip'] = $this->request->getRemoteAddress();
		return $headers;
	}
}
