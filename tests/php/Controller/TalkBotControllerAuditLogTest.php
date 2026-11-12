<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCA\AppAPI\Controller\TalkBotController;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\TalkBotsService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkBotControllerAuditLogTest extends TestCase {
	public static function signatureProvider(): array {
		return [
			'wrong signature' => [false],
			'right signature' => [true],
		];
	}

	#[DataProvider('signatureProvider')]
	public function testProxyTalkMessageLogsAWrongSignature(bool $validSignature): void {
		$random = 'random_value';
		$signature = $validSignature ? hash_hmac('sha256', $random, 'bot_secret') : str_repeat('0', 64);
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$request->method('getParams')->willReturn([]);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => match ($name) {
			'x-nextcloud-talk-random' => $random,
			'x-nextcloud-talk-signature' => $signature,
			default => '',
		});
		$exAppService = $this->createMock(ExAppService::class);
		$exAppService->method('getExApp')->willReturn(new ExApp(['appid' => 'test_app', 'enabled' => 1]));
		$talkBotsService = $this->createMock(TalkBotsService::class);
		$talkBotsService->method('getTalkBotSecret')->willReturn('bot_secret');
		$auditLogger = $this->createMock(AuditLogger::class);
		if ($validSignature) {
			$auditLogger->expects(self::never())->method('log');
		} else {
			$auditLogger->expects(self::once())->method('log')->with(
				Operation::TalkBotAuthFailed,
				'Failed Talk bot authentication for ExApp %s',
				['appId' => 'test_app', 'route' => 'talk_bot'],
			);
		}
		$appAPIService = $this->createMock(AppAPIService::class);
		$appAPIService->method('requestToExApp')->willReturn($this->createMock(IResponse::class));
		$controller = new TalkBotController(
			$request,
			$exAppService,
			$appAPIService,
			$talkBotsService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IThrottler::class),
			$auditLogger,
		);

		$response = $controller->proxyTalkMessage('test_app', 'talk_bot');

		self::assertSame($validSignature ? Http::STATUS_OK : Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
