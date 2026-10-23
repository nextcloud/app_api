<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\SetupChecks;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\HarpService;
use OCA\AppAPI\SetupChecks\HarpVersionCheck;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HarpVersionCheckTest extends TestCase {

	private function runCheck(string $harpVersion, int $expectedCacheTtl): SetupResult {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters = []) => vsprintf($text, $parameters));
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getRegisteredDaemonConfigs')->willReturn([
			new DaemonConfig(['name' => 'harp_proxy', 'deploy_config' => ['harp' => ['exapp_direct' => false]]]),
		]);
		$harpService = $this->createMock(HarpService::class);
		$harpService->method('getHarpVersion')->willReturn($harpVersion);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects(self::once())->method('set')->with(self::anything(), $harpVersion, $expectedCacheTtl);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(true);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$check = new HarpVersionCheck($l10n, $this->createMock(LoggerInterface::class), $daemonConfigService, $harpService, $cacheFactory);
		return $check->run();
	}

	public function testHarpWithoutRouteVerbChecksIsTooOld(): void {
		$result = $this->runCheck('0.4.7', 60 * 60);
		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertStringContainsString('"0.4.7", which is too old. The minimum required version is "0.4.8"', $result->getDescription());
	}

	public function testCurrentHarpPasses(): void {
		$result = $this->runCheck('0.4.8', 60 * 60 * 24 * 7);
		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}
}
