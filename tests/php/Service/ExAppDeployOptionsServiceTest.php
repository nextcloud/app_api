<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Db\ExAppDeployOptionsMapper;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExAppDeployOptionsServiceTest extends TestCase {
	private ExAppDeployOptionsService $service;
	private ExAppDeployOptionsMapper&MockObject $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ExAppDeployOptionsMapper::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$this->service = new ExAppDeployOptionsService(
			$this->createMock(LoggerInterface::class),
			$this->mapper,
			$cacheFactory,
		);
	}

	/**
	 * Two apps with distinct env-var keys. Raw fetch returns both; filtered fetch
	 * returns only the requested app's rows.
	 */
	public function testGetDeployOptionsFiltersByAppId(): void {
		$this->mapper->method('findAll')->willReturn($this->twoAppRecords());

		$all = $this->service->getDeployOptions();
		self::assertCount(4, $all);

		$appA = $this->service->getDeployOptions('app_a');
		self::assertCount(2, $appA);
		foreach ($appA as $entry) {
			self::assertSame('app_a', $entry->getAppid());
		}

		$appB = $this->service->getDeployOptions('app_b');
		self::assertCount(2, $appB);
		foreach ($appB as $entry) {
			self::assertSame('app_b', $entry->getAppid());
		}
	}

	/**
	 * Regression guard for issue #808.
	 *
	 * `formatDeployOptions` keys by `type`, so passing the unfiltered result
	 * (rows for every app) causes the last app in iteration order to overwrite
	 * entries for earlier apps. Calling `getDeployOptions($appId)` first is the
	 * only way to get a clean per-app map.
	 */
	public function testFormatDeployOptionsUnfilteredOverwritesAcrossApps(): void {
		$this->mapper->method('findAll')->willReturn($this->twoAppRecords());

		$unfiltered = $this->service->formatDeployOptions($this->service->getDeployOptions());
		// Last app in iteration order wins: app_b's keys leak into the env_vars entry.
		self::assertArrayHasKey('environment_variables', $unfiltered);
		self::assertSame(
			['APP_B_VAR'],
			array_keys($unfiltered['environment_variables']),
			'Unfiltered format is order-dependent: last app overwrites earlier apps.',
		);

		$filtered = $this->service->formatDeployOptions($this->service->getDeployOptions('app_a'));
		self::assertSame(
			['APP_A_VAR_1', 'APP_A_VAR_2'],
			array_keys($filtered['environment_variables']),
			'Filtered format returns only the requested app\'s env vars.',
		);
	}

	/**
	 * @return list<array{id: int, appid: string, type: string, value: array<string, mixed>}>
	 */
	private function twoAppRecords(): array {
		return [
			[
				'id' => 1,
				'appid' => 'app_a',
				'type' => 'environment_variables',
				'value' => [
					'APP_A_VAR_1' => ['name' => 'APP_A_VAR_1', 'value' => 'a1'],
					'APP_A_VAR_2' => ['name' => 'APP_A_VAR_2', 'value' => 'a2'],
				],
			],
			[
				'id' => 2,
				'appid' => 'app_a',
				'type' => 'mounts',
				'value' => [],
			],
			[
				'id' => 3,
				'appid' => 'app_b',
				'type' => 'environment_variables',
				'value' => [
					'APP_B_VAR' => ['name' => 'APP_B_VAR', 'value' => 'b1'],
				],
			],
			[
				'id' => 4,
				'appid' => 'app_b',
				'type' => 'mounts',
				'value' => [],
			],
		];
	}
}
