<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Middleware;

use OCA\AppAPI\Attribute\MaintenanceModeAvailable;
use OCA\AppAPI\Controller\AppConfigController;
use OCA\AppAPI\Controller\ExAppsPageController;
use OCA\AppAPI\Controller\HarpController;
use OCA\AppAPI\Controller\OCSApiController;
use OCA\AppAPI\Controller\OCSExAppController;
use OCA\AppAPI\Controller\PreferencesController;
use OCA\AppAPI\Controller\TalkBotController;
use OCA\AppAPI\Exceptions\MaintenanceModeException;
use OCA\AppAPI\Middleware\MaintenanceModeMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MaintenanceModeTestController extends Controller {
	#[MaintenanceModeAvailable]
	public function allowedRoute(): void {
	}

	public function blockedRoute(): void {
	}
}

class MaintenanceModeMiddlewareTest extends TestCase {
	private IConfig $config;
	private MaintenanceModeTestController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->controller = new MaintenanceModeTestController('app_api', $this->createMock(IRequest::class));
	}

	private function middleware(bool $maintenance): MaintenanceModeMiddleware {
		$this->config->method('getSystemValueBool')->with('maintenance', false)->willReturn($maintenance);
		return new MaintenanceModeMiddleware($this->config);
	}

	public function testPassesEveryRouteWhenNotInMaintenance(): void {
		$this->middleware(false)->beforeController($this->controller, 'blockedRoute');
		$this->addToAssertionCount(1);
	}

	public function testPassesAllowedRouteDuringMaintenance(): void {
		$this->middleware(true)->beforeController($this->controller, 'allowedRoute');
		$this->addToAssertionCount(1);
	}

	public function testBlocksUnmarkedRouteDuringMaintenance(): void {
		$this->expectException(MaintenanceModeException::class);
		$this->middleware(true)->beforeController($this->controller, 'blockedRoute');
	}

	public function testExceptionIsAnsweredWithMaintenanceResponse(): void {
		$exception = new MaintenanceModeException();
		$response = $this->middleware(false)->afterException($this->controller, 'blockedRoute', $exception);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('1', $response->getHeaders()['X-Nextcloud-Maintenance-Mode']);
		self::assertSame('120', $response->getHeaders()['Retry-After']);
		self::assertSame(['message' => $exception->getMessage()], $response->getData());
	}

	public function testBlockedRouteInMaintenanceYields503EndToEnd(): void {
		$middleware = $this->middleware(true);

		try {
			$middleware->beforeController($this->controller, 'blockedRoute');
			self::fail('Expected MaintenanceModeException to be thrown');
		} catch (MaintenanceModeException $exception) {
			$response = $middleware->afterException($this->controller, 'blockedRoute', $exception);
		}

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('1', $response->getHeaders()['X-Nextcloud-Maintenance-Mode']);
		self::assertSame('120', $response->getHeaders()['Retry-After']);
	}

	public function testUnrelatedExceptionIsRethrown(): void {
		$exception = new \RuntimeException('boom');
		$this->expectExceptionObject($exception);
		$this->middleware(false)->afterException($this->controller, 'blockedRoute', $exception);
	}

	/**
	 * @return list<array{class-string, string}>
	 */
	public static function allowlistedRoutes(): array {
		return [
			[HarpController::class, 'getExAppMetadata'],
			[OCSApiController::class, 'setAppInitProgress'],
			[OCSApiController::class, 'setAppInitProgressDeprecated'],
			[OCSApiController::class, 'getEnabledState'],
			[OCSApiController::class, 'getNextcloudAbsoluteUrl'],
			[OCSApiController::class, 'log'],
		];
	}

	#[DataProvider('allowlistedRoutes')]
	public function testAllowlistedRouteCarriesAttribute(string $class, string $method): void {
		$attributes = (new ReflectionMethod($class, $method))->getAttributes(MaintenanceModeAvailable::class);
		self::assertNotEmpty($attributes, $class . '::' . $method . ' must stay reachable during maintenance');
	}

	/**
	 * @return list<array{class-string, string}>
	 */
	public static function blockedRoutes(): array {
		return [
			[HarpController::class, 'getUserInfo'],
			[OCSApiController::class, 'getNCUsersList'],
			[OCSExAppController::class, 'getExAppsList'],
			[AppConfigController::class, 'setAppConfigValue'],
			[AppConfigController::class, 'getAppConfigValues'],
			[PreferencesController::class, 'setUserConfigValue'],
			[PreferencesController::class, 'getUserConfigValues'],
			[ExAppsPageController::class, 'uninstallApp'],
			[TalkBotController::class, 'registerExAppTalkBot'],
		];
	}

	#[DataProvider('blockedRoutes')]
	public function testNonAllowlistedRouteHasNoAttribute(string $class, string $method): void {
		$attributes = (new ReflectionMethod($class, $method))->getAttributes(MaintenanceModeAvailable::class);
		self::assertEmpty($attributes, $class . '::' . $method . ' must return 503 during maintenance');
	}
}
