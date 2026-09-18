<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Service\DaemonConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DaemonConfigServiceTest extends TestCase {

	public static function resolveImageRegistryProvider(): array {
		$mirror = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		return [
			'no registries key' => [[], 'ghcr.io', 'ghcr.io'],
			'empty registries' => [['registries' => []], 'ghcr.io', 'ghcr.io'],
			'matching mapping' => [['registries' => [$mirror]], 'ghcr.io', 'registry.example.com'],
			'other registry is left alone' => [['registries' => [$mirror]], 'docker.io', 'docker.io'],
			'the matching mapping is picked among several' => [
				['registries' => [['from' => 'docker.io', 'to' => 'hub.example.com'], $mirror]],
				'ghcr.io',
				'registry.example.com',
			],
			'trailing slashes of the target are dropped' => [
				['registries' => [['from' => 'ghcr.io', 'to' => 'registry.example.com//']]],
				'ghcr.io',
				'registry.example.com',
			],
			'target with port and path' => [
				['registries' => [['from' => 'ghcr.io', 'to' => 'registry.example.com:5000/mirror/ghcr']]],
				'ghcr.io',
				'registry.example.com:5000/mirror/ghcr',
			],
			'local keeps the registry' => [['registries' => [['from' => 'ghcr.io', 'to' => 'local']]], 'ghcr.io', 'ghcr.io'],
			'legacy duplicate source: the local entry is skipped' => [
				['registries' => [['from' => 'ghcr.io', 'to' => 'local'], $mirror]],
				'ghcr.io',
				'registry.example.com',
			],
			'malformed entries are ignored' => [
				['registries' => ['ghcr.io', ['from' => 'ghcr.io'], ['from' => 'ghcr.io', 'to' => 5000], ['from' => 'ghcr.io', 'to' => '/'], $mirror]],
				'ghcr.io',
				'registry.example.com',
			],
			'only an exact match counts' => [['registries' => [$mirror]], 'my.ghcr.io', 'my.ghcr.io'],
			'match is case sensitive' => [['registries' => [$mirror]], 'GHCR.IO', 'GHCR.IO'],
			'registries with gaps in their keys' => [['registries' => [2 => $mirror]], 'ghcr.io', 'registry.example.com'],
		];
	}

	#[DataProvider('resolveImageRegistryProvider')]
	public function testResolveImageRegistry(array $deployConfig, string $imageRegistry, string $expected): void {
		self::assertSame($expected, DaemonConfigService::resolveImageRegistry($deployConfig, $imageRegistry));
	}
}
