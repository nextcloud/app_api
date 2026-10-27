<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppMapper;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppOccService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ExAppSetupCheckService;
use OCA\AppAPI\Service\ProvidersAI\TaskProcessingService;
use OCA\AppAPI\Service\TalkBotsService;
use OCA\AppAPI\Service\UI\FilesActionsMenuService;
use OCA\AppAPI\Service\UI\InitialStateService;
use OCA\AppAPI\Service\UI\ScriptsService;
use OCA\AppAPI\Service\UI\SettingsService;
use OCA\AppAPI\Service\UI\StylesService;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\DB\Exception;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExAppServiceAuditLogTest extends TestCase {
	private ExAppService $service;
	private ExAppMapper&MockObject $exAppMapper;
	private AuditLogger&MockObject $auditLogger;

	protected function setUp(): void {
		parent::setUp();

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$this->exAppMapper = $this->createMock(ExAppMapper::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);

		$this->service = new ExAppService(
			$this->createMock(LoggerInterface::class),
			$cacheFactory,
			$this->createMock(IUserManager::class),
			$this->createMock(ExAppFetcher::class),
			$this->createMock(ExAppArchiveFetcher::class),
			$this->exAppMapper,
			$this->createMock(TopMenuService::class),
			$this->createMock(InitialStateService::class),
			$this->createMock(ScriptsService::class),
			$this->createMock(StylesService::class),
			$this->createMock(FilesActionsMenuService::class),
			$this->createMock(TaskProcessingService::class),
			$this->createMock(TalkBotsService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ExAppOccService::class),
			$this->createMock(ExAppDeployOptionsService::class),
			$this->createMock(ExAppSetupCheckService::class),
			$this->createMock(IConfig::class),
			$this->auditLogger,
		);
	}

	private static function appInfo(): array {
		return [
			'id' => 'test_app',
			'version' => '1.0.0',
			'name' => 'Test App',
			'daemon_config_name' => 'harp_proxy',
			'port' => 23000,
			'secret' => 'the_secret_of_the_exapp',
		];
	}

	private static function exApp(): ExApp {
		return new ExApp([
			'appid' => 'test_app',
			'version' => '1.0.0',
			'name' => 'Test App',
			'daemon_config_name' => 'harp_proxy',
			'port' => 23000,
			'secret' => 'the_secret_of_the_exapp',
		]);
	}

	public function testRegisterExAppLogsTheExAppWithoutItsSecret(): void {
		$this->exAppMapper->expects(self::once())->method('insert')->willReturnArgument(0);
		$this->exAppMapper->method('findByAppId')->willReturn(self::exApp());
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppRegistered,
			'ExApp %s (version %s) was registered on deploy daemon %s',
			['appId' => 'test_app', 'version' => '1.0.0', 'daemonName' => 'harp_proxy', 'port' => 23000],
		);

		self::assertInstanceOf(ExApp::class, $this->service->registerExApp(self::appInfo()));
	}

	public function testRegisterExAppDoesNotLogADatabaseError(): void {
		$this->exAppMapper->method('insert')->willThrowException(new Exception('duplicate appid'));
		$this->auditLogger->expects(self::never())->method('log');

		self::assertNull($this->service->registerExApp(self::appInfo()));
	}

	public function testUnregisterExAppLogsTheExApp(): void {
		$this->exAppMapper->method('findAll')->willReturn([self::exApp()]);
		$this->exAppMapper->expects(self::once())->method('deleteExApp')->with('test_app')->willReturn(1);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppUnregistered,
			'ExApp %s was unregistered',
			['appId' => 'test_app'],
		);

		self::assertTrue($this->service->unregisterExApp('test_app'));
	}

	public function testUnregisterExAppDoesNotLogWhenNothingWasDeleted(): void {
		$this->exAppMapper->method('findAll')->willReturn([self::exApp()]);
		$this->exAppMapper->method('deleteExApp')->willReturn(0);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->unregisterExApp('test_app'));
	}

	public function testUnregisterExAppDoesNotLogAnUnknownExApp(): void {
		$this->exAppMapper->method('findAll')->willReturn([]);
		$this->exAppMapper->expects(self::never())->method('deleteExApp');
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->unregisterExApp('test_app'));
	}

	public function testUpdateExAppInfoLogsBothVersions(): void {
		$this->exAppMapper->expects(self::once())->method('updateExApp');
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppUpdated,
			'ExApp %s was updated from version %s to %s',
			['appId' => 'test_app', 'oldVersion' => '1.0.0', 'newVersion' => '1.1.0'],
		);

		self::assertTrue($this->service->updateExAppInfo(self::exApp(), ['version' => '1.1.0', 'name' => 'Test App']));
	}

	public function testUpdateExAppInfoDoesNotLogADatabaseError(): void {
		$this->exAppMapper->method('updateExApp')->willThrowException(new Exception('database is gone'));
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->updateExAppInfo(self::exApp(), ['version' => '1.1.0', 'name' => 'Test App']));
	}

	public function testLogExAppDataDeleted(): void {
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppDataDeleted,
			'Data of ExApp %s was deleted from deploy daemon %s',
			['appId' => 'test_app', 'daemonName' => 'harp_proxy'],
		);

		$this->service->logExAppDataDeleted(self::exApp());
	}
}
