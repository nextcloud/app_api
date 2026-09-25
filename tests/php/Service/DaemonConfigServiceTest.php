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
			'legacy duplicate source: the first entry wins' => [
				['registries' => [['from' => 'ghcr.io', 'to' => 'local'], $mirror]],
				'ghcr.io',
				'ghcr.io',
			],
			'local with a trailing slash keeps the registry' => [
				['registries' => [['from' => 'ghcr.io', 'to' => 'local/']]],
				'ghcr.io',
				'ghcr.io',
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

	public static function resolveRegistryTargetProvider(): array {
		$mirror = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		$local = ['from' => 'ghcr.io', 'to' => 'local'];
		return [
			'no registries key' => [[], null],
			'no mapping of the registry' => [['registries' => [['from' => 'docker.io', 'to' => 'local']]], null],
			'mirror' => [['registries' => [$mirror]], 'registry.example.com'],
			'mirror with trailing slashes' => [['registries' => [['from' => 'ghcr.io', 'to' => 'registry.example.com//']]], 'registry.example.com'],
			'local' => [['registries' => [$local]], 'local'],
			'local with a trailing slash' => [['registries' => [['from' => 'ghcr.io', 'to' => 'local/']]], 'local'],
			'first usable entry wins' => [['registries' => [$local, $mirror]], 'local'],
			'first usable entry wins, mirror first' => [['registries' => [$mirror, $local]], 'registry.example.com'],
			'unusable entries are skipped' => [
				['registries' => ['ghcr.io', ['from' => 'ghcr.io'], ['from' => 'ghcr.io', 'to' => 5000], ['from' => 'ghcr.io', 'to' => '/'], $mirror]],
				'registry.example.com',
			],
			'only unusable entries' => [['registries' => [['from' => 'ghcr.io', 'to' => '//']]], null],
		];
	}

	#[DataProvider('resolveRegistryTargetProvider')]
	public function testResolveRegistryTarget(array $deployConfig, ?string $expected): void {
		self::assertSame($expected, DaemonConfigService::resolveRegistryTarget($deployConfig, 'ghcr.io'));
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
			'whitespace only' => [['from' => ' ', 'to' => "\t"]],
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

	public function testAddDockerRegistryIgnoresAnUnusableStoredEntryOfTheSameSource(): void {
		$unusable = ['from' => 'ghcr.io', 'to' => '/'];
		$added = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => [$unusable]]]);

		$result = $this->createService()->addDockerRegistry($daemonConfig, $added);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame([$unusable, $added], $result->getDeployConfig()['registries']);
		self::assertSame('registry.example.com', DaemonConfigService::resolveImageRegistry($result->getDeployConfig(), 'ghcr.io'));
	}

	public function testAddDockerRegistryStoresNormalisedSourceAndTarget(): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => []]);

		$result = $this->createService()
			->addDockerRegistry($daemonConfig, ['from' => ' ghcr.io/ ', 'to' => 'registry.example.com//', 'extra' => 'x']);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame([['from' => 'ghcr.io', 'to' => 'registry.example.com']], $result->getDeployConfig()['registries']);
	}

	public function testAddDockerRegistryStoresLocalWithoutATrailingSlash(): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => []]);

		$result = $this->createService()->addDockerRegistry($daemonConfig, ['from' => 'ghcr.io', 'to' => 'local/']);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame([['from' => 'ghcr.io', 'to' => 'local']], $result->getDeployConfig()['registries']);
	}

	public function testRemoveDockerRegistryWithoutAnyMapping(): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => ['net' => 'host']]);

		$result = $this->createService(expectUpdate: false)
			->removeDockerRegistry($daemonConfig, ['from' => 'ghcr.io', 'to' => 'registry.example.com']);

		self::assertSame(['error' => 'This Docker registry map does not exist'], $result);
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

	public function testRemoveDockerRegistryToleratesMalformedEntries(): void {
		$first = ['from' => 'docker.io', 'to' => 'hub.example.com'];
		$second = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => ['junk', ['from' => 'quay.io'], $first, $second]]]);

		$result = $this->createService()->removeDockerRegistry($daemonConfig, $first);

		self::assertInstanceOf(DaemonConfig::class, $result);
		self::assertSame(['junk', ['from' => 'quay.io'], $second], $result->getDeployConfig()['registries']);
	}
}
