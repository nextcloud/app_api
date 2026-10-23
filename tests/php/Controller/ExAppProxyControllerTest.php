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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class ExAppProxyControllerTest extends TestCase {
	// A public GET route listed before an admin POST route of the same URL.
	private const SAME_URL = [
		['url' => '^/settings$', 'verb' => 'GET', 'access_level' => 0],
		['url' => '^/settings$', 'verb' => 'POST', 'access_level' => 2],
	];

	/**
	 * The route the proxy picks for this request, or [] when it refuses it.
	 */
	private function routeFor(string $method, string $path, array $routes, ?string $userId = null, bool $isAdmin = false): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$controller = new ExAppProxyController(
			$request,
			$this->createMock(AppAPIService::class),
			$this->createMock(ExAppService::class),
			$this->createMock(IMimeTypeDetector::class),
			$this->createMock(ContentSecurityPolicyNonceManager::class),
			$userId,
			$groupManager,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IThrottler::class),
		);
		$exApp = new ExApp(['appid' => 'test-proxy', 'routes' => $routes]);
		return (new ReflectionMethod($controller, 'passesExAppProxyRoutesChecks'))->invoke($controller, $exApp, $path);
	}

	public function testFirstRouteMatchingPathAndVerbDecides(): void {
		self::assertSame('GET', $this->routeFor('GET', 'settings', self::SAME_URL)['verb']);
		self::assertSame([], $this->routeFor('POST', 'settings', self::SAME_URL));
		self::assertSame([], $this->routeFor('POST', 'settings', self::SAME_URL, 'alice'));
		self::assertSame('POST', $this->routeFor('POST', 'settings', self::SAME_URL, 'admin', true)['verb']);
		self::assertSame([], $this->routeFor('PUT', 'settings', self::SAME_URL, 'admin', true));
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
}
