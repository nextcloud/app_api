<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Service\AppAPICommonService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppAPICommonServiceTest extends TestCase {

	public static function resourceLimitsProvider(): array {
		return [
			'no limits' => [[], []],
			'empty limits' => [['resourceLimits' => []], []],
			'whole numbers stay as they are' => [
				['resourceLimits' => ['memory' => 536870912, 'nanoCPUs' => 2000000000]],
				['memory' => 536870912, 'nanoCPUs' => 2000000000],
			],
			// What the admin settings store for 1.07 and 2.01 CPUs.
			'a fraction above is rounded' => [['resourceLimits' => ['nanoCPUs' => 1070000000.0000001]], ['nanoCPUs' => 1070000000]],
			'a fraction below is rounded' => [['resourceLimits' => ['nanoCPUs' => 2009999999.9999998]], ['nanoCPUs' => 2010000000]],
			'a fractional memory value is rounded' => [['resourceLimits' => ['memory' => 512.3 * 1024 * 1024]], ['memory' => 537185485]],
			'other values are passed on for HaRP to check' => [
				['resourceLimits' => ['nanoCPUs' => '1.5', 'memory' => 1e30, 'other' => 1.5]],
				['nanoCPUs' => '1.5', 'memory' => 1e30, 'other' => 1.5],
			],
			'not a list of limits' => [['resourceLimits' => 'x'], []],
		];
	}

	#[DataProvider('resourceLimitsProvider')]
	public function testBuildResourceLimits(array $deployConfig, array $expected): void {
		$this->assertSame($expected, AppAPICommonService::buildResourceLimits($deployConfig));
	}
}
