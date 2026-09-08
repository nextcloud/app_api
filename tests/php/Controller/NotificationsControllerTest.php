<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use DateTime;
use OCA\AppAPI\Controller\NotificationsController;
use OCA\AppAPI\Notifications\ExNotificationsManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationsControllerTest extends TestCase {

	private const APP_ID = 'test_exapp';
	private const PARAMS = ['object' => 'file', 'object_id' => '42', 'subject_type' => 'done', 'subject_params' => []];

	private NotificationsController $controller;
	private ExNotificationsManager&MockObject $exNotificationsManager;
	private IAppManager&MockObject $appManager;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([
			['ex-app-id', self::APP_ID],
			['authorization-app-api', base64_encode('alice:secret')],
		]);
		$this->exNotificationsManager = $this->createMock(ExNotificationsManager::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new NotificationsController(
			$request, $this->exNotificationsManager, $this->appManager, $this->logger,
		);
	}

	public function testSendNotificationFailsLoudlyWhenNotificationsAppIsNotEnabled(): void {
		$this->appManager->method('isEnabledForAnyone')->with('notifications')->willReturn(false);
		$this->exNotificationsManager->expects($this->never())->method('sendNotification');
		$this->logger->expects($this->once())->method('warning');

		$this->expectException(OCSNotFoundException::class);
		$this->controller->sendNotification(self::PARAMS);
	}

	public function testSendNotificationDeliversWhenNotificationsAppIsEnabled(): void {
		$this->appManager->method('isEnabledForAnyone')->with('notifications')->willReturn(true);
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(self::APP_ID);
		$notification->method('getUser')->willReturn('alice');
		$notification->method('getDateTime')->willReturn(new DateTime('2026-01-01T00:00:00+00:00'));
		$notification->method('getObjectType')->willReturn('file');
		$notification->method('getObjectId')->willReturn('42');
		$notification->method('getParsedSubject')->willReturn('');
		$notification->method('getParsedMessage')->willReturn('');
		$notification->method('getLink')->willReturn('');
		$notification->method('getIcon')->willReturn('');
		$this->exNotificationsManager->expects($this->once())
			->method('sendNotification')
			->with(self::APP_ID, 'alice', self::PARAMS)
			->willReturn($notification);
		$this->logger->expects($this->never())->method('warning');

		$response = $this->controller->sendNotification(self::PARAMS);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::APP_ID, $response->getData()['app']);
		$this->assertSame('alice', $response->getData()['user']);
	}
}
