<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\DaemonConfigMapper;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

	private function createService(bool $expectUpdate = true): DaemonConfigService {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->expects($expectUpdate ? self::once() : self::never())->method('update')->willReturnArgument(0);
		return new DaemonConfigService(
			$this->createMock(LoggerInterface::class),
			$mapper,
			$this->createMock(ExAppService::class),
			$this->createMock(ICrypto::class),
		);
	}

	public function testAddDockerRegistryKeepsAList(): void {
		$stored = ['from' => 'docker.io', 'to' => 'hub.example.com'];
		$added = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => [1 => $stored]]]);

		$result = $this->createService()->addDockerRegistry($daemonConfig, $added);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame([$stored, $added], $result->getDeployConfig()['registries']);
	}

	public static function unusableRegistryMapProvider(): array {
		return [
			'no source' => [['to' => 'registry.example.com']],
			'no target' => [['from' => 'ghcr.io']],
			'empty source' => [['from' => '', 'to' => 'registry.example.com']],
			'empty target' => [['from' => 'ghcr.io', 'to' => '']],
			'target of slashes only' => [['from' => 'ghcr.io', 'to' => '//']],
			'target is not a string' => [['from' => 'ghcr.io', 'to' => 5000]],
			'source is not a string' => [['from' => ['ghcr.io'], 'to' => 'registry.example.com']],
		];
	}

	#[DataProvider('unusableRegistryMapProvider')]
	public function testAddDockerRegistryRejectsAnUnusableMap(array $registryMap): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => []]]);

		$result = $this->createService(expectUpdate: false)->addDockerRegistry($daemonConfig, $registryMap);

		self::assertSame(['error' => 'The source and target registry cannot be empty'], $result);
		self::assertSame([], $daemonConfig->getDeployConfig()['registries']);
	}

	public function testAddDockerRegistryRejectsADuplicateSource(): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => ['junk', ['from' => 'ghcr.io', 'to' => 'local']]]]);

		$result = $this->createService(expectUpdate: false)
			->addDockerRegistry($daemonConfig, ['from' => 'ghcr.io', 'to' => 'registry.example.com']);

		self::assertSame(['error' => 'This Docker registry map from "ghcr.io" already exists'], $result);
	}

	public function testAddDockerRegistryStoresOnlySourceAndTarget(): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => []]);

		$result = $this->createService()
			->addDockerRegistry($daemonConfig, ['from' => 'ghcr.io', 'to' => 'registry.example.com/', 'extra' => 'x']);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame([['from' => 'ghcr.io', 'to' => 'registry.example.com/']], $result->getDeployConfig()['registries']);
	}

	public function testRemoveDockerRegistryKeepsAList(): void {
		$service = $this->createService();
		$first = ['from' => 'docker.io', 'to' => 'hub.example.com'];
		$second = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => [$first, $second]]]);

		$result = $service->removeDockerRegistry($daemonConfig, $first);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame([$second], $result->getDeployConfig()['registries']);
	}
}
