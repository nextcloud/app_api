<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\AppAPI\Controller\ExAppProxyController;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\Files\IMimeTypeDetector;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class ExAppProxyControllerTest extends TestCase {
	// A public GET route listed before an admin POST route of the same URL.
	private const SAME_URL = [
		['url' => '^/settings$', 'verb' => 'GET', 'access_level' => 0],
		['url' => '^/settings$', 'verb' => 'POST', 'access_level' => 2],
	];

	private function controller(string $method, ?string $userId = null, bool $isAdmin = false, ?ExApp $exApp = null): ExAppProxyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$exAppService = $this->createMock(ExAppService::class);
		$exAppService->method('getExApp')->willReturn($exApp);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		return new ExAppProxyController(
			$request,
			$this->createMock(AppAPIService::class),
			$exAppService,
			$this->createMock(IMimeTypeDetector::class),
			$this->createMock(ContentSecurityPolicyNonceManager::class),
			$userId,
			$groupManager,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IThrottler::class),
		);
	}

	/**
	 * The route the proxy picks for this request, or [] when it refuses it.
	 */
	private function routeFor(string $method, string $path, array $routes, ?string $userId = null, bool $isAdmin = false): array {
		$controller = $this->controller($method, $userId, $isAdmin);
		$exApp = new ExApp(['appid' => 'test-proxy', 'routes' => $routes]);
		return (new ReflectionMethod($controller, 'passesExAppProxyRoutesChecks'))->invoke($controller, $exApp, $path);
	}

	/**
	 * Whether the proxy forwards a public GET for this path to an ExApp with a catch-all route.
	 */
	private function isForwarded(string $path): bool {
		$exApp = new ExApp(['appid' => 'test-proxy', 'enabled' => 1, 'routes' => [['url' => '.*', 'verb' => 'GET', 'access_level' => 0]]]);
		$controller = $this->controller('GET', exApp: $exApp);
		$route = [];
		$bruteforceProtection = [];
		$delay = 0;
		$args = ['test-proxy', $path, &$route, &$bruteforceProtection, &$delay];
		return (new ReflectionMethod($controller, 'prepareProxy'))->invokeArgs($controller, $args) !== null;
	}

	public function testFirstRouteMatchingPathAndVerbDecides(): void {
		self::assertSame('GET', $this->routeFor('GET', 'settings', self::SAME_URL)['verb']);
		self::assertSame([], $this->routeFor('POST', 'settings', self::SAME_URL));
		self::assertSame([], $this->routeFor('POST', 'settings', self::SAME_URL, 'alice'));
		self::assertSame('POST', $this->routeFor('POST', 'settings', self::SAME_URL, 'admin', true)['verb']);
		self::assertSame([], $this->routeFor('PUT', 'settings', self::SAME_URL, 'admin', true));
	}

	public function testAccessDeniedDoesNotFallThroughToLaterRoutes(): void {
		// An admin route listed before a broader user route, as some ExApps declare their setup pages.
		$adminFirst = [
			['url' => '^/setup$', 'verb' => 'GET,POST', 'access_level' => 2],
			['url' => '^/.*', 'verb' => 'GET,POST', 'access_level' => 1],
		];
		self::assertSame([], $this->routeFor('GET', 'setup', $adminFirst, 'alice'));
		self::assertSame('^/.*', $this->routeFor('GET', 'other', $adminFirst, 'alice')['url']);
	}

	public static function internalEndpointProvider(): array {
		return [
			'heartbeat' => ['heartbeat'],
			'init' => ['init'],
			'enabled' => ['enabled'],
			'trailing slash' => ['init/'],
			'other case' => ['Enabled'],
			'encoded leading slash' => ['%2Fheartbeat'],
			'percent-encoded' => ['%65nabled'],
			'followed by a decoded "?"' => ['enabled?enabled=0'],
			'followed by a decoded "#"' => ['init#x'],
			'followed by a newline' => ["heartbeat\n"],
			'with a ";" parameter, dropped by Tomcat' => ['heartbeat;x'],
			'followed by an escaped "?", where a wrapper ExApp cuts the decoded path' => ['enabled%3Fenabled=0'],
		];
	}

	#[DataProvider('internalEndpointProvider')]
	public function testInternalEndpointIsRefused(string $path): void {
		self::assertFalse($this->isForwarded($path));
	}

	public function testPathsNearInternalEndpointsAreForwarded(): void {
		self::assertTrue($this->isForwarded('heartbeats'));
		self::assertTrue($this->isForwarded('api/enabled'));
	}

	public static function stricterReadingProvider(): array {
		// The path, and the stricter route that only the ExApp's reading of it matches.
		return [
			'";" parameter, dropped by Tomcat' => ['admin;x/a.txt', '^/admin/.*'],
			'";" parameter, dropped by Tomcat before it decodes' => ['admin;x%3F/a.txt', '^/admin/.*'],
			'escaped ";" and "?", decoded by nginx with a URI in proxy_pass and dropped by Tomcat' => ['%3Bx%3Fy/admin', '^/admin$'],
			'decoded "?", where the forwarded path ends' => ['admin?x', '^/admin$'],
			'decoded "#", where the forwarded path ends' => ['admin#x', '^/admin$'],
			'escape, decoded by the ExApp' => ['%61dmin', '^/admin$'],
			'escaped "?", kept in the name by Starlette' => ['%66iles/a%3F.txt', '^/files/.+\.txt$'],
			'"//", merged by Tomcat, nginx and Go' => ['admin//x', '^/admin/x$'],
			'"//" with a ";" and an escaped "?" in the name, merged by nginx' => ['files//a;b%3F.txt', '^/files/[^/]+\.txt$'],
			'leading "//x" with an escaped "/", read as a host by Python\'s urlsplit before it decodes' => ['/x%2Fy/admin%2Fx', '^/admin/x$'],
			'leading "//x", with "%2F" kept in its segment by URL parsers' => ['/x/files/a%2Fb', '^/files/[^/]+$'],
			'escaped "/", kept in its segment by aiohttp and Go' => ['%61dmin/a%2Fb', '^/admin/[^/]+$'],
			'escaped "/" kept in its segment, with "//" merged' => ['//files/a.txt%2Fa.txt', '^/files/[^/]+\.txt$'],
			'trailing slash, ignored by Express' => ['admin/', '^/admin$'],
			'trailing slash and another letter case, both ignored by Express' => ['Admin/', '^/admin$'],
			'escaped "?", where a wrapper ExApp cuts the decoded path it re-sends' => ['admin%3Fx', '^/admin$'],
			'escaped "?" after a ";", where a wrapper ExApp cuts the decoded path it re-sends' => ['files/a;b.txt%3Fz', '^/files/.+\.txt$'],
			'escaped "/" and "?" after a ";", where a wrapper ExApp re-sends the decoded path to nginx' => ['files/%2Fa;b.txt%3Fz', '^/files/[^/]+\.txt$'],
			'escaped ";", which a wrapper ExApp re-sends to Tomcat' => ['admin%3Bx/a.txt', '^/admin/.*'],
			'decoded "?", with a route URL without a leading slash' => ['admin?x', '^admin$'],
		];
	}

	#[DataProvider('stricterReadingProvider')]
	public function testStricterRouteOfAnotherReadingApplies(string $path, string $adminUrl): void {
		$routes = [
			['url' => $adminUrl, 'verb' => 'GET', 'access_level' => 2],
			['url' => '.*', 'verb' => 'GET', 'access_level' => 0],
		];
		self::assertSame([], $this->routeFor('GET', $path, $routes));
		self::assertSame($adminUrl, $this->routeFor('GET', $path, $routes, 'admin', true)['url']);
	}

	public function testRouteOfAnotherLetterCaseCanOnlyRaiseTheAccessLevel(): void {
		// A case-sensitive ExApp serves "/PUB/x" with its user route.
		$routes = [
			['url' => '^/pub/.*', 'verb' => 'GET', 'access_level' => 0],
			['url' => '.*', 'verb' => 'GET', 'access_level' => 1],
		];
		self::assertSame([], $this->routeFor('GET', 'PUB/x', $routes));
		self::assertSame('.*', $this->routeFor('GET', 'PUB/x', $routes, 'alice')['url']);
		self::assertSame('^/pub/.*', $this->routeFor('GET', 'PUB/x', [$routes[0]])['url']);
	}

	public function testHeadIsAcceptedWhereGetIs(): void {
		// Nextcloud routes HEAD to the proxy's GET action, and HaRP accepts HEAD on GET routes too.
		self::assertSame('GET', $this->routeFor('HEAD', 'settings', self::SAME_URL)['verb']);
		self::assertSame([], $this->routeFor('HEAD', 'form', [['url' => '^/form$', 'verb' => 'POST', 'access_level' => 0]]));
	}

	public function testVerbAsAppApiStoresIt(): void {
		// AppAPI stores the text of <verb> as it is in info.xml, line breaks and case included.
		$routes = [['url' => '^/z$', 'verb' => "\n      get, POST\n    ", 'access_level' => 0]];
		self::assertSame('^/z$', $this->routeFor('GET', 'z', $routes)['url']);
		self::assertSame('^/z$', $this->routeFor('POST', 'z', $routes)['url']);
		self::assertSame([], $this->routeFor('PUT', 'z', $routes));
	}

	public static function dotSegmentProvider(): array {
		return [
			'in the middle' => ['a/../b'],
			'at the end' => ['a/..'],
			'percent-encoded in the middle' => ['a/%2e%2e/b'],
			'percent-encoded at the end' => ['a/%2E%2e'],
			'with an empty parameter in the middle' => ['a/..;/b'],
			'with an empty parameter at the end' => ['a/..;'],
			'with a parameter in the middle' => ['a/..;x=1/b'],
			'with a parameter at the end' => ['a/..;x=1'],
			'with an escaped parameter, which a proxy decodes' => ['a/..%3Bx/b'],
			'followed by a decoded "?"' => ['a/..?/b'],
			'followed by a decoded "#"' => ['a/..#x'],
			'followed by an escaped "?", where a wrapper ExApp cuts the decoded path' => ['a/..%3Fx'],
			'followed by an escaped "#", where a wrapper ExApp cuts the decoded path' => ['a/..%23x'],
			'current directory in the middle' => ['a/./b'],
			'current directory at the start' => ['./a'],
			'current directory with a parameter' => ['a/.;/b'],
			'percent-encoded current directory' => ['a/%2e/b'],
		];
	}

	#[DataProvider('dotSegmentProvider')]
	public function testDotSegmentIsRefused(string $path): void {
		self::assertFalse($this->isForwarded($path));
	}

	public static function forwardedPathProvider(): array {
		return [
			'dots inside a segment' => ['a..b'],
			'dots starting a segment' => ['..foo'],
			'path parameter' => ['file;v=1'],
			'dot starting a directory' => ['.well-known/x'],
		];
	}

	#[DataProvider('forwardedPathProvider')]
	public function testPathIsForwarded(string $path): void {
		self::assertTrue($this->isForwarded($path));
	}
}
