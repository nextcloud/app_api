<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\OCSExAppController;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for OCSExAppController::getExAppsList. Replaces
 * nc_py_api/tests/actual_tests/apps_test.py::test_ex_app_get_list*.
 *
 * Inserts a fake ex_apps row in setUp so the listing has a known entry to assert on, instead of depending on an
 * externally registered fixture.
 */
#[Group('DB')]
class OCSExAppControllerTest extends TestCase {
	use RegistersFakeExAppTrait;

	private const ENABLED_APP_ID = 'phpunit_exapp_list_enabled';
	private const DISABLED_APP_ID = 'phpunit_exapp_list_disabled';
	private const ENABLED_PORT = 19010;
	private const DISABLED_PORT = 19011;

	private OCSExAppController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->insertFakeExApp(self::ENABLED_APP_ID, self::ENABLED_PORT, enabled: 1);
		$this->insertFakeExApp(self::DISABLED_APP_ID, self::DISABLED_PORT, enabled: 0);
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$this->controller = new OCSExAppController(
			$request,
			Server::get(AppAPIService::class),
			Server::get(ExAppService::class),
			Server::get(IURLGenerator::class),
		);
	}

	protected function tearDown(): void {
		$this->deleteFakeExApp(self::ENABLED_APP_ID);
		$this->deleteFakeExApp(self::DISABLED_APP_ID);
		parent::tearDown();
	}

	public function testGetExAppsListAllIncludesBoth(): void {
		$response = $this->controller->getExAppsList('all');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$ids = array_column($response->getData(), 'id');
		self::assertContains(self::ENABLED_APP_ID, $ids);
		self::assertContains(self::DISABLED_APP_ID, $ids);
	}

	public function testGetExAppsListEnabledFiltersDisabled(): void {
		$response = $this->controller->getExAppsList('enabled');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$ids = array_column($response->getData(), 'id');
		self::assertContains(self::ENABLED_APP_ID, $ids);
		self::assertNotContains(self::DISABLED_APP_ID, $ids);
		foreach ($response->getData() as $row) {
			self::assertTrue($row['enabled'], 'list=enabled returned a disabled ExApp: ' . ($row['id'] ?? '?'));
		}
	}

	public function testGetExAppsListInvalidValueReturnsBadRequest(): void {
		$response = $this->controller->getExAppsList('not_a_valid_value');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testGetExAppFormatsKnownEntry(): void {
		$response = $this->controller->getExApp(self::ENABLED_APP_ID);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(self::ENABLED_APP_ID, $data['id']);
		self::assertSame('1.0.0', $data['version']);
		self::assertTrue($data['enabled']);
	}

	public function testGetExAppMissingReturns404(): void {
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->getExApp('phpunit_does_not_exist')->getStatus()
		);
	}
}
