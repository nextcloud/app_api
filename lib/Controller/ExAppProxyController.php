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
use OCA\AppAPI\Db\ExAppMapper;
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
		IRequest $request,
		private readonly AppAPIService $service,
		private readonly ExAppService $exAppService,
		private readonly IMimeTypeDetector $mimeTypeHelper,
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
		private readonly ?string $userId,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly IThrottler $throttler,
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
		string $appId, string $other, array &$route, array &$bruteforceProtection, int &$delay,
	): ?ExApp {
		$delay = 0;
		// A dot segment curl resolves, also before ";" (Tomcat drops parameters), "?" or "#" (they end the path), raw or as an escape a proxy or wrapper ExApp decodes.
		if (preg_match('#(?:^|/|%2[fF])(?:\.|%2[eE]){1,2}(?:[/;?\#]|%2[fF]|%3[bBfF]|%23|$)#', $other) === 1) {
			$this->logger->debug(
				sprintf('Returning status 404 for "%s": path contains a dot segment.', $other)
			);
			return null;
		}
		// In any reading of the path, any case, without surrounding slashes and newlines.
		$targets = array_map(static fn (string $path): string => strtolower(trim($path, "/\n")), self::exAppPathReadings($other));
		if (array_intersect($targets, ['heartbeat', 'init', 'enabled']) !== []) {
			$this->logger->debug(
				sprintf('Returning status 404 for "%s": only AppAPI calls this ExApp endpoint.', $other)
			);
			return null;
		}
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
		$bruteforceProtection = ExAppMapper::parseJsonList($route['bruteforce_protection'] ?? null);
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
		$readings = self::exAppPathReadings($exAppRoute);
		$route = $this->firstExAppRoute($exApp, $readings[0], 'i');
		if ($route === [] || !$this->passesExAppProxyRouteAccessLevelCheck($route['access_level'])) {
			return [];
		}
		// As in HaRP, the route of another reading of the path or another letter case can only raise the access level.
		foreach ($readings as $path) {
			foreach (['', 'i'] as $flags) {
				$candidate = $this->firstExAppRoute($exApp, $path, $flags);
				if ($candidate !== [] && $candidate['access_level'] > $route['access_level']) {
					$route = $candidate;
				}
			}
		}
		// Apply the access level of the decided route without falling through to broader routes.
		return $this->passesExAppProxyRouteAccessLevelCheck($route['access_level']) ? $route : [];
	}

	private function firstExAppRoute(ExApp $exApp, string $canonicalSubject, string $flags): array {
		// Route URL is a regex matched against the request path including its leading slash, mirroring HaRP's target_path semantics.
		$method = strtoupper($this->request->getMethod());
		foreach ($exApp->getRoutes() as $route) {
			$pattern = '~^(?:' . str_replace('~', '\\~', $route['url']) . ')~' . $flags;
			$matched = preg_match($pattern, $canonicalSubject) === 1;
			if (!$matched && preg_match($pattern, substr($canonicalSubject, 1)) === 1) {
				// TODO(deprecation): remove this bare-path fallback once known ExApps have migrated their info.xml route URLs to the canonical `^/path$` form. Tracked by the AppAPI / context_chat_backend coordination effort.
				$this->logger->debug(sprintf(
					'ExApp "%s" matched route "%s" via legacy bare-path fallback. Update the info.xml route URL to start with "/" or "^/" so it matches against "%s".',
					$exApp->getAppid(), $route['url'], $canonicalSubject
				));
				$matched = true;
			}
			if ($matched && self::routeAcceptsMethod($route['verb'], $method)) {
				return $route;
			}
		}
		return [];
	}

	/**
	 * The path as checked, then as ExApp servers read the forwarded one, like HaRP's exapp_paths.
	 */
	private static function exAppPathReadings(string $other): array {
		$path = '/' . $other;
		$sent = substr($path, 0, strcspn($path, '?#')); // the forwarded path ends at "?" or "#"
		$decoded = rawurldecode($sent);
		$resent = substr($decoded, 0, strcspn($decoded, '?#')); // a wrapper ExApp re-sending the decoded path cuts it there
		$readings = [
			$path,
			$sent,
			$decoded, // most servers decode the path before routing
			preg_replace('#//+#', '/', $decoded),
			rawurldecode(str_ireplace('%2F', '%252F', $sent)), // Express, aiohttp and Go keep "%2F" in its segment
			preg_replace('#//+#', '/', rawurldecode(str_ireplace('%2F', '%252F', $sent))), // the same with "//" merged, as HaRP reads it
			preg_replace('#//+#', '/', rawurldecode(preg_replace('#;[^/]*#', '', $sent))), // Tomcat drops ";" parameters first
			preg_replace('#//+#', '/', preg_replace('#;[^/]*#', '', $decoded)), // Tomcat behind a proxy that decodes the path, such as nginx with a URI in proxy_pass
			$resent,
			preg_replace('#//+#', '/', $resent), // nginx and Go behind such a wrapper merge "//"
			preg_replace('#//+#', '/', preg_replace('#;[^/]*#', '', $resent)), // Tomcat behind such a wrapper
			rawurldecode(preg_replace('#^//+[^/]*(?:/|$)#', '/', $sent)), // WHATWG and Python URL parsers read a leading "//x" as a host
			rawurldecode(preg_replace('#^//+[^/]*(?:/|$)#', '/', str_ireplace('%2F', '%252F', $sent))), // and keep "%2F" in its segment
		];
		foreach ($readings as $reading) {
			if (strlen($reading) > 1 && str_ends_with($reading, '/')) {
				$readings[] = substr($reading, 0, -1); // Express and Spring 5 ignore a trailing slash
			}
		}
		return array_unique($readings);
	}

	/**
	 * As in HaRP: the verb lists methods separated by commas or whitespace, and HEAD is accepted wherever GET is.
	 */
	private static function routeAcceptsMethod(string $verb, string $method): bool {
		$methods = array_map('strtoupper', preg_split('/[\s,]+/', $verb, -1, PREG_SPLIT_NO_EMPTY) ?: []);
		return in_array($method, $methods, true) || ($method === 'HEAD' && in_array('GET', $methods, true));
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
		$headersToExclude = array_map(
			'strtolower',
			array_filter(ExAppMapper::parseJsonList($route['headers_to_exclude'] ?? null), 'is_string')
		);

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
