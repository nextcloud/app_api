<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\AppConfigController;
use OCA\AppAPI\Service\ExAppConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for AppConfigController. Targets the `sensitive` flag round-trip — the only test in nc_py_api's
 * appcfg_prefs_ex_test.py that actually validated AppAPI behavior (the rest exercised nc_py_api wrapper validation).
 */
#[Group('DB')]
class AppConfigControllerTest extends TestCase {
	private const TEST_APP_ID = 'phpunit_appcfg_test';
	private const KEY_PLAIN = 'phpunit_plain_key';
	private const KEY_SECRET = 'phpunit_secret_key';

	private AppConfigController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private ExAppConfigService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);
		$this->service = Server::get(ExAppConfigService::class);
		$this->controller = new AppConfigController($this->request, $this->service);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->service->deleteAppConfigValues([self::KEY_PLAIN, self::KEY_SECRET], self::TEST_APP_ID);
	}

	public function testSensitiveFlagPersistsOnSet(): void {
		$response = $this->controller->setAppConfigValue(self::KEY_SECRET, '123', sensitive: 1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$entity = $response->getData();
		self::assertSame('123', $entity->getConfigvalue());
		self::assertSame(1, $entity->getSensitive());
	}

	public function testNonSensitiveDefaultsToZero(): void {
		$response = $this->controller->setAppConfigValue(self::KEY_PLAIN, 'plain', sensitive: null);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		// The persisted entity has sensitive=0 when null is passed (no flag set).
		self::assertSame(0, $response->getData()->getSensitive());
	}

	public function testSensitiveFlagPreservedOnUpdateWithoutFlag(): void {
		// First write: sensitive=1.
		$this->controller->setAppConfigValue(self::KEY_SECRET, 'orig', sensitive: 1);
		// Second write to the SAME key without specifying sensitive — the existing flag must stay at 1.
		// ExAppConfigService only calls setSensitive() when $sensitive !== null.
		$response = $this->controller->setAppConfigValue(self::KEY_SECRET, 'updated', sensitive: null);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $response->getData()->getSensitive());
	}

	public function testGetReturnsAllKeys(): void {
		$this->controller->setAppConfigValue(self::KEY_PLAIN, 'a', sensitive: 0);
		$this->controller->setAppConfigValue(self::KEY_SECRET, 'b', sensitive: 1);

		$response = $this->controller->getAppConfigValues([self::KEY_PLAIN, self::KEY_SECRET]);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$rows = $response->getData();
		self::assertCount(2, $rows);
		// Verify that getAppConfigValues decrypts sensitive values back to plaintext on read.
		// Without decryption the caller would get the encrypted blob and not the original 'b'.
		$byKey = array_column($rows, 'configvalue', 'configkey');
		self::assertSame('a', $byKey[self::KEY_PLAIN]);
		self::assertSame('b', $byKey[self::KEY_SECRET]);
	}

	public function testEmptyKeyRejected(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->controller->setAppConfigValue('', 'x');
	}

	public function testDeleteMissingThrowsNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->controller->deleteAppConfigValues(['no_such_key_phpunit_xyz']);
	}
}
