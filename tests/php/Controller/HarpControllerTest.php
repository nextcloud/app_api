<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCA\AppAPI\Controller\HarpController;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HarpControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private ExAppService&MockObject $exAppService;
	private AuditLogger&MockObject $auditLogger;
	private IThrottler&MockObject $throttler;
	private HarpController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->exAppService = $this->createMock(ExAppService::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $value): string => substr($value, strlen('encrypted:')));
		$harpService = $this->createMock(HarpService::class);
		$harpService->method('getHarpExApp')->willReturn([]);
		$this->controller = new HarpController(
			$this->request,
			$this->exAppService,
			$this->createMock(LoggerInterface::class),
			$this->throttler,
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$crypto,
			null,
			$harpService,
			$this->auditLogger,
		);
	}

	public static function harpKeyProvider(): array {
		return [
			'wrong key' => ['wrong_key_1234', Http::STATUS_UNAUTHORIZED],
			'no key' => ['', Http::STATUS_UNAUTHORIZED],
			'right key' => ['harp_key_12345', Http::STATUS_OK],
		];
	}

	#[DataProvider('harpKeyProvider')]
	public function testExAppMetadataLogsAFailedHarpAuthentication(string $headerKey, int $status): void {
		$exApp = new ExApp(['appid' => 'test_app']);
		$exApp->setDeployConfig(['haproxy_password' => 'encrypted:harp_key_12345']);
		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->request->method('getHeader')->with('harp-shared-key')->willReturn($headerKey);
		if ($status === Http::STATUS_OK) {
			$this->throttler->expects(self::never())->method('sleepDelayOrThrowOnMax');
			$this->auditLogger->expects(self::never())->method('log');
		} else {
			$this->auditLogger->expects(self::once())->method('log')->with(
				Operation::HarpAuthFailed,
				'Failed HaRP authentication for ExApp %s',
				['appId' => 'test_app'],
			);
		}

		self::assertSame($status, $this->controller->getExAppMetadata('test_app')->getStatus());
	}

	public function testExAppMetadataDoesNotLogAnUnknownExApp(): void {
		$this->exAppService->method('getExApp')->willReturn(null);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->getExAppMetadata('unknown_app')->getStatus());
	}

	public function testExAppMetadataStopsLoggingAtTheThrottleLimit(): void {
		$exApp = new ExApp(['appid' => 'test_app']);
		$exApp->setDeployConfig(['haproxy_password' => 'encrypted:harp_key_12345']);
		$this->exAppService->method('getExApp')->willReturn($exApp);
		$this->request->method('getHeader')->willReturn('wrong_key_1234');
		$this->throttler->method('sleepDelayOrThrowOnMax')->willThrowException(new MaxDelayReached());
		$this->auditLogger->expects(self::never())->method('log');

		$this->expectException(MaxDelayReached::class);
		$this->controller->getExAppMetadata('test_app');
	}
}
