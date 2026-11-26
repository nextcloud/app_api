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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase {
	public function testLogDispatchesACriticalActionWithTheOperation(): void {
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(static function (CriticalActionPerformedEvent $event): bool {
				self::assertSame('Deploy daemon harp_proxy was unregistered', $event->getLogMessage());
				self::assertSame(['daemonName' => 'harp_proxy'], $event->getParameters());
				self::assertFalse($event->getObfuscateParameters());
				self::assertSame('app_api.daemon.unregistered', $event->getOperation());
				return true;
			}));

		(new AuditLogger($eventDispatcher))->log(Operation::DaemonUnregistered, 'Deploy daemon %s was unregistered', ['daemonName' => 'harp_proxy']);
	}

	public function testLogKeepsValuesFromChangingTheEntry(): void {
		$parameters = ['daemonName' => '{operation}{params}', 'host' => '100%s'];
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(static function (CriticalActionPerformedEvent $event) use ($parameters): bool {
				self::assertSame('Deploy daemon (operation)(params) on 100%%s was registered', $event->getLogMessage());
				self::assertSame('Deploy daemon (operation)(params) on 100%s was registered', vsprintf($event->getLogMessage(), array_values($parameters)));
				self::assertSame($parameters, $event->getParameters());
				return true;
			}));

		(new AuditLogger($eventDispatcher))->log(Operation::DaemonRegistered, 'Deploy daemon %s on %s was registered', $parameters);
	}

	public function testLogForExAppAddsTheAppId(): void {
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(static function (CriticalActionPerformedEvent $event): bool {
				self::assertSame('Item (x) was created by 100%%s', $event->getLogMessage());
				self::assertSame(['item' => '{x}', 'user' => '100%s', 'appId' => 'test_app'], $event->getParameters());
				self::assertSame('test_app.item.created', $event->getOperation());
				return true;
			}));

		(new AuditLogger($eventDispatcher))->logForExApp('test_app', 'test_app.item.created', 'Item %s was created by %s', ['item' => '{x}', 'user' => '100%s']);
	}

	public static function dataInvalidExAppEntries(): array {
		return [
			'empty app ID' => ['', 'test_app.item.created', 'Created', []],
			'operation of another app' => ['test_app', 'app_api.ex_app.enabled', 'ExApp enabled', []],
			'operation of an app whose ID starts the same' => ['test', 'test_app.item.created', 'Created', []],
			'operation without entity' => ['test_app', 'test_app.created', 'Created', []],
			'reserved parameter' => ['test_app', 'test_app.item.created', 'Created for %s', ['appId' => 'other_app']],
			'nested parameter' => ['test_app', 'test_app.item.created', 'Created %s', ['item' => ['a']]],
			'unnamed parameter' => ['test_app', 'test_app.item.created', 'Created %s', ['a']],
			'too many placeholders' => ['test_app', 'test_app.item.created', 'Created %s by %s at %s', ['item' => 'a']],
		];
	}

	#[DataProvider('dataInvalidExAppEntries')]
	public function testLogForExAppRejectsInvalidEntries(string $appId, string $operation, string $message, array $parameters): void {
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->expects(self::never())->method('dispatchTyped');

		$this->expectException(\InvalidArgumentException::class);
		(new AuditLogger($eventDispatcher))->logForExApp($appId, $operation, $message, $parameters);
	}
}
