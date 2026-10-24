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
			'followed by a decoded "?"' => ['a/..?/b'],
			'followed by a decoded "#"' => ['a/..#x'],
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
