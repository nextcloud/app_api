<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\AuditLog;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase {
	public function testLogDispatchesACriticalActionWithTheOperation(): void {
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(static function (CriticalActionPerformedEvent $event): bool {
				self::assertSame('Deploy daemon %s was unregistered', $event->getLogMessage());
				self::assertSame(['daemonName' => 'harp_proxy'], $event->getParameters());
				self::assertFalse($event->getObfuscateParameters());
				self::assertTrue(AuditEvents::hasOperation($event, 'app_api.daemon.unregistered'));
				return true;
			}));

		(new AuditLogger($eventDispatcher))->log(Operation::DaemonUnregistered, 'Deploy daemon %s was unregistered', ['daemonName' => 'harp_proxy']);
	}
}
