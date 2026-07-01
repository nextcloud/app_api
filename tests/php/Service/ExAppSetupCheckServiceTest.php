<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Service\ExAppSetupCheckService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExAppSetupCheckServiceTest extends TestCase {
	private ExAppSetupCheckService $service;
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->service = new ExAppSetupCheckService($this->appConfig);
	}

	public function testOptInStoresMarker(): void {
		$this->appConfig->expects(self::once())->method('setValueString')
			->with('app_api', 'setup_checks_myapp', '1')->willReturn(true);
		$this->service->optIn('myapp');
	}

	public function testOptInIgnoresOverlongAppId(): void {
		// KEY_PREFIX (13) + appId must stay within IAppConfig's 64-char key limit.
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->service->optIn(str_repeat('a', 60));
	}

	public function testOptOutDeletesWhenPresent(): void {
		$this->appConfig->method('hasKey')->willReturn(true);
		$this->appConfig->expects(self::once())->method('deleteKey')->with('app_api', 'setup_checks_myapp');
		$this->service->optOut('myapp');
	}

	public function testOptOutNoopWhenAbsent(): void {
		$this->appConfig->method('hasKey')->willReturn(false);
		$this->appConfig->expects(self::never())->method('deleteKey');
		// state is empty -> no rewrite either
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->service->optOut('myapp');
	}

	public function testOptOutEvictsAppFromState(): void {
		$this->appConfig->method('hasKey')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn(json_encode([
			'apps' => ['myapp' => [['severity' => 'error', 'text' => 'x']], 'other' => [['severity' => 'warning', 'text' => 'y']]],
			'updatedAt' => 1,
		]));
		$stored = null;
		$this->appConfig->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v) use (&$stored): bool {
			$stored = json_decode($v, true);
			return true;
		});
		$this->service->optOut('myapp');
		self::assertArrayNotHasKey('myapp', $stored['apps']);
		self::assertArrayHasKey('other', $stored['apps']);
	}

	public function testGetOptedInAppIdsFiltersPrefixAndExtractsAppId(): void {
		$this->appConfig->method('getKeys')->with('app_api')
			->willReturn(['version', 'loglevel', 'setup_checks_appA', 'setup_checks_live_transcription', 'setupchecks_state']);
		// state key ('setupchecks_state') deliberately does NOT match the prefix and must be excluded.
		self::assertSame(['appA', 'live_transcription'], $this->service->getOptedInAppIds());
	}

	public function testStoreAndGetStateRoundTrip(): void {
		$stored = '';
		$this->appConfig->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v) use (&$stored): bool {
			$stored = $v;
			return true;
		});
		$this->appConfig->method('getValueString')->willReturnCallback(function (string $a, string $k, string $d = '') use (&$stored): string {
			return $stored !== '' ? $stored : $d;
		});

		$this->service->storeState(['a' => [['severity' => 'warning', 'text' => 'x']]]);
		$state = $this->service->getState();
		self::assertSame(['a' => [['severity' => 'warning', 'text' => 'x']]], $state['apps']);
		self::assertGreaterThan(0, $state['updatedAt']);
	}

	public function testGetStateIsDefensiveOnGarbage(): void {
		$this->appConfig->method('getValueString')->willReturn('not-json{');
		$state = $this->service->getState();
		self::assertSame([], $state['apps']);
		self::assertSame(0, $state['updatedAt']);
	}
}
