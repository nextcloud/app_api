<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\PreferencesController;
use OCA\AppAPI\Service\ExAppPreferenceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for PreferencesController against the server's IUserConfig storage (oc_preferences).
 * Mirrors AppConfigControllerTest, covering the sensitive round-trip + the sticky-flag downgrade.
 */
#[Group('DB')]
class PreferencesControllerTest extends TestCase {
	private const TEST_APP_ID = 'phpunit_pref_test';
	private const TEST_USER_ID = 'phpunit_pref_user';
	private const KEY_PLAIN = 'phpunit_plain_key';
	private const KEY_SECRET = 'phpunit_secret_key';

	private PreferencesController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private ExAppPreferenceService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => strtoupper($name) === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::TEST_USER_ID);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->service = Server::get(ExAppPreferenceService::class);
		$this->controller = new PreferencesController($this->request, $userSession, $this->service);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->service->deleteUserConfigValues([self::KEY_PLAIN, self::KEY_SECRET], self::TEST_USER_ID, self::TEST_APP_ID);
	}

	public function testSensitiveFlagPersistsOnSet(): void {
		$response = $this->controller->setUserConfigValue(self::KEY_SECRET, '123', sensitive: 1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('123', $response->getData()['configvalue']);
		self::assertSame(1, $response->getData()['sensitive']);
	}

	public function testNonSensitiveDefaultsToZero(): void {
		$response = $this->controller->setUserConfigValue(self::KEY_PLAIN, 'plain', sensitive: null);
		self::assertSame(0, $response->getData()['sensitive']);
	}

	public function testSensitiveFlagPreservedOnUpdateWithoutFlag(): void {
		$this->controller->setUserConfigValue(self::KEY_SECRET, 'orig', sensitive: 1);
		$response = $this->controller->setUserConfigValue(self::KEY_SECRET, 'updated', sensitive: null);
		self::assertSame(1, $response->getData()['sensitive']);
	}

	public function testSensitiveDowngradeClearsFlag(): void {
		$this->controller->setUserConfigValue(self::KEY_SECRET, '123', sensitive: 1);
		$response = $this->controller->setUserConfigValue(self::KEY_SECRET, '123', sensitive: 0);
		self::assertSame(0, $response->getData()['sensitive']);
		$rows = $this->controller->getUserConfigValues([self::KEY_SECRET])->getData();
		self::assertSame('123', array_column($rows, 'configvalue', 'configkey')[self::KEY_SECRET]);
	}

	public function testGetReturnsDecryptedValues(): void {
		$this->controller->setUserConfigValue(self::KEY_PLAIN, 'a', sensitive: 0);
		$this->controller->setUserConfigValue(self::KEY_SECRET, 'b', sensitive: 1);

		$rows = $this->controller->getUserConfigValues([self::KEY_PLAIN, self::KEY_SECRET])->getData();
		self::assertCount(2, $rows);
		$byKey = array_column($rows, 'configvalue', 'configkey');
		self::assertSame('a', $byKey[self::KEY_PLAIN]);
		self::assertSame('b', $byKey[self::KEY_SECRET]);
	}

	public function testEmptySensitiveValueRoundTrips(): void {
		$this->controller->setUserConfigValue(self::KEY_SECRET, '', sensitive: 1);
		$rows = $this->controller->getUserConfigValues([self::KEY_SECRET])->getData();
		self::assertSame('', array_column($rows, 'configvalue', 'configkey')[self::KEY_SECRET]);
	}

	public function testEmptyKeyRejected(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->controller->setUserConfigValue('', 'x');
	}

	public function testDeleteMissingThrowsNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->controller->deleteUserConfigValues(['no_such_key_phpunit_xyz']);
	}
}
