<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

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
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * getAppInfo-level coverage for the environment-variable normalization wiring: the info.xml
 * path must feed declared variables through ExAppEnvVarsHelper, so an empty <default></default>
 * (parsed to [] by the simplexml/json roundtrip) never reaches the deploy actions.
 */
class ExAppServiceGetAppInfoTest extends TestCase {
	private ExAppService $service;
	private string $infoXmlPath = '';

	protected function setUp(): void {
		parent::setUp();

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$this->service = new ExAppService(
			$this->createMock(LoggerInterface::class),
			$cacheFactory,
			$this->createMock(IUserManager::class),
			$this->createMock(ExAppFetcher::class),
			$this->createMock(ExAppArchiveFetcher::class),
			$this->createMock(ExAppMapper::class),
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
		);
	}

	protected function tearDown(): void {
		if ($this->infoXmlPath !== '' && file_exists($this->infoXmlPath)) {
			unlink($this->infoXmlPath);
		}
		parent::tearDown();
	}

	private function writeInfoXml(string $environmentVariables): string {
		$this->infoXmlPath = tempnam(sys_get_temp_dir(), 'appapi-test-info-') . '.xml';
		file_put_contents($this->infoXmlPath, <<<XML
<?xml version="1.0"?>
<info>
	<id>test_app</id>
	<external-app>
		<environment-variables>
$environmentVariables
		</environment-variables>
	</external-app>
</info>
XML);
		return $this->infoXmlPath;
	}

	public function testEmptyDefaultElementIsDroppedFromInfoXml(): void {
		$infoXml = $this->writeInfoXml(<<<XML
			<variable>
				<name>EMPTY_ELEM</name>
				<display-name>Empty</display-name>
				<description>d</description>
				<default></default>
			</variable>
			<variable>
				<name>NO_DEFAULT</name>
				<display-name>No default</display-name>
			</variable>
			<variable>
				<name>KEPT</name>
				<display-name>Kept</display-name>
				<default>v</default>
			</variable>
XML);

		$appInfo = $this->service->getAppInfo('test_app', $infoXml, null);

		self::assertArrayNotHasKey('error', $appInfo);
		self::assertSame(
			['KEPT' => ['name' => 'KEPT', 'displayName' => 'Kept', 'description' => '', 'default' => 'v', 'value' => 'v']],
			$appInfo['external-app']['environment-variables']
		);
	}

	public function testDeployOptionsOverrideDeclaredVariables(): void {
		$infoXml = $this->writeInfoXml(<<<XML
			<variable>
				<name>A</name>
				<default>x</default>
			</variable>
			<variable>
				<name>B</name>
				<default>y</default>
			</variable>
XML);

		$appInfo = $this->service->getAppInfo('test_app', $infoXml, null, [
			'environment_variables' => ['A' => 'overridden', 'B' => ''],
		]);

		self::assertArrayNotHasKey('error', $appInfo);
		self::assertSame(
			['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => 'overridden']],
			$appInfo['external-app']['environment-variables']
		);
	}

	public function testVariableWithEmptyNameElementReturnsError(): void {
		$infoXml = $this->writeInfoXml(<<<XML
			<variable>
				<name/>
				<default>v</default>
			</variable>
XML);

		$appInfo = $this->service->getAppInfo('test_app', $infoXml, null);

		self::assertArrayHasKey('error', $appInfo);
		self::assertStringContainsString('invalid environment variable definition', $appInfo['error']);
	}
}
