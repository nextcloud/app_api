<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCA\AppAPI\Controller\ConfigController;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigControllerTest extends TestCase {
	private ConfigController $controller;
	private IAppConfig&MockObject $appConfig;
	private AuditLogger&MockObject $auditLogger;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);
		$this->controller = new ConfigController('app_api', $this->createMock(IRequest::class), $this->appConfig, $this->auditLogger);
	}

	public function testSetAdminConfigLogsEachChangedSetting(): void {
		$this->appConfig->expects(self::exactly(3))->method('setValueString')
			->willReturnCallback(static fn (string $app, string $key, string $value): bool => $key !== 'init_timeout');
		$logged = [];
		$this->auditLogger->expects(self::exactly(2))->method('log')
			->willReturnCallback(static function (Operation $operation, string $message, array $parameters) use (&$logged): void {
				$logged[] = [$operation, $message, $parameters];
			});

		$this->controller->setAdminConfig([
			'default_daemon_config' => 'harp_proxy',
			'init_timeout' => '40',
			'container_restart_policy' => 'always',
		]);

		self::assertSame([
			[Operation::AdminConfigUpdated, 'AppAPI setting %s was changed to %s', ['key' => 'default_daemon_config', 'value' => 'harp_proxy']],
			[Operation::AdminConfigUpdated, 'AppAPI setting %s was changed to %s', ['key' => 'container_restart_policy', 'value' => 'always']],
		], $logged);
	}

	public function testSetAdminConfigDoesNotLogTheValueOfAnUnknownSetting(): void {
		$this->appConfig->method('setValueString')->willReturn(true);
		$this->auditLogger->expects(self::once())->method('log')
			->with(Operation::AdminConfigUpdated, 'AppAPI setting %s was changed', ['key' => 'some_token']);

		$this->controller->setAdminConfig(['some_token' => 'a secret value']);
	}

	public function testSetAdminConfigDoesNotLogAnUnchangedSetting(): void {
		$this->appConfig->method('setValueString')->willReturn(false);
		$this->auditLogger->expects(self::never())->method('log');

		$this->controller->setAdminConfig(['init_timeout' => '40']);
	}
}
