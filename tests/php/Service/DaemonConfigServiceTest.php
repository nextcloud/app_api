<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\DaemonConfigMapper;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;
use OCP\DB\Exception;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DaemonConfigServiceTest extends TestCase {

	private AuditLogger&MockObject $auditLogger;

	protected function setUp(): void {
		parent::setUp();
		$this->auditLogger = $this->createMock(AuditLogger::class);
	}

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
			'whitespace-only target is unusable' => [['registries' => [['from' => 'ghcr.io', 'to' => '   ']]], null],
			'stored target with surrounding whitespace' => [['registries' => [['from' => 'ghcr.io', 'to' => ' registry.example.com ']]], 'registry.example.com'],
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
			$this->auditLogger,
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
		$this->auditLogger->expects(self::never())->method('log');
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => []]]);

		$result = $this->createService(expectUpdate: false)->addDockerRegistry($daemonConfig, $registryMap);

		self::assertSame(['error' => 'The source and target registry cannot be empty'], $result);
		self::assertSame([], $daemonConfig->getDeployConfig()['registries']);
	}

	public function testAddDockerRegistryRejectsADuplicateSource(): void {
		$this->auditLogger->expects(self::never())->method('log');
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
		$daemonConfig = new DaemonConfig(['name' => 'harp_proxy', 'deploy_config' => []]);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::DaemonRegistryAdded,
			'Docker registry mapping was added to deploy daemon %s: %s to %s',
			['daemonName' => 'harp_proxy', 'from' => 'ghcr.io', 'to' => 'registry.example.com'],
		);

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
		$this->auditLogger->expects(self::never())->method('log');
		$daemonConfig = new DaemonConfig(['deploy_config' => ['net' => 'host']]);

		$result = $this->createService(expectUpdate: false)
			->removeDockerRegistry($daemonConfig, ['from' => 'ghcr.io', 'to' => 'registry.example.com']);

		self::assertSame(['error' => 'This Docker registry map does not exist'], $result);
	}

	public function testRemoveDockerRegistryOnlyMatchesTheExactMapping(): void {
		$this->auditLogger->expects(self::never())->method('log');
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => [['from' => 'ghcr.io', 'to' => 'registry.example.com']]]]);

		$result = $this->createService(expectUpdate: false)->removeDockerRegistry($daemonConfig, ['from' => true, 'to' => true]);

		self::assertSame(['error' => 'This Docker registry map does not exist'], $result);
	}

	public function testRemoveDockerRegistryKeepsAList(): void {
		$service = $this->createService();
		$first = ['from' => 'docker.io', 'to' => 'hub.example.com'];
		$second = ['from' => 'ghcr.io', 'to' => 'registry.example.com'];
		$daemonConfig = new DaemonConfig(['name' => 'harp_proxy', 'deploy_config' => ['registries' => [$first, $second]]]);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::DaemonRegistryRemoved,
			'Docker registry mapping was removed from deploy daemon %s: %s to %s',
			['daemonName' => 'harp_proxy', 'from' => 'docker.io', 'to' => 'hub.example.com'],
		);

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

	private function createServiceWithLogger(LoggerInterface $logger): DaemonConfigService {
		return new DaemonConfigService(
			$logger,
			$this->createMock(DaemonConfigMapper::class),
			$this->createMock(ExAppService::class),
			$this->createMock(ICrypto::class),
			$this->auditLogger,
		);
	}

	private static function daemonParams(string $protocol, bool $isHarp): array {
		return [
			'name' => 'test_daemon',
			'protocol' => $protocol,
			'deploy_config' => ['harp' => $isHarp ? ['frp_address' => 'appapi-harp:8782'] : null],
		];
	}

	public static function isSecretRequiredProvider(): array {
		return [
			'HaRP' => ['http', true, true],
			'HaRP over HTTPS' => ['https', true, true],
			'HTTPS Docker Socket Proxy' => ['https', false, true],
			'HTTP Docker Socket Proxy' => ['http', false, false],
		];
	}

	#[DataProvider('isSecretRequiredProvider')]
	public function testIsSecretRequired(string $protocol, bool $isHarp, bool $expected): void {
		self::assertSame($expected, DaemonConfigService::isSecretRequired(self::daemonParams($protocol, $isHarp)));
	}

	public static function validateNewSecretProvider(): array {
		$harpError = 'The HaRP shared key must be at least 12 characters long.';
		return [
			'HaRP with a short key' => ['http', true, 'eleven_char', $harpError],
			'HaRP without a key' => ['http', true, '', $harpError],
			'HaRP with a 12 character key' => ['http', true, 'twelve_chars', null],
			'HaRP key length counts characters, not bytes' => ['http', true, 'ключ_ключ', $harpError],
			'HTTPS Docker Socket Proxy with a short password' => ['https', false, 'short', 'The HaProxy password must be at least 12 characters long.'],
			'HTTP Docker Socket Proxy with a short password' => ['http', false, 'short', null],
			'HTTP Docker Socket Proxy without a password' => ['http', false, '', null],
		];
	}

	#[DataProvider('validateNewSecretProvider')]
	public function testValidateNewSecret(string $protocol, bool $isHarp, string $secret, ?string $expected): void {
		$service = $this->createServiceWithLogger($this->createMock(LoggerInterface::class));
		self::assertSame($expected, $service->validateNewSecret(self::daemonParams($protocol, $isHarp), $secret));
	}

	public function testValidateNewSecretAcceptsAnExampleSecretWithAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with(self::stringContains('The HaRP shared key is an example value from the documentation.'));

		$result = $this->createServiceWithLogger($logger)->validateNewSecret(self::daemonParams('http', true), 'some_very_secure_password');

		self::assertNull($result);
	}

	public function testValidateNewSecretDoesNotWarnAboutAGeneratedSecret(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$result = $this->createServiceWithLogger($logger)->validateNewSecret(self::daemonParams('http', true), bin2hex(random_bytes(16)));

		self::assertNull($result);
	}

	private function createServiceWithMapper(DaemonConfigMapper $mapper, ?ExAppService $exAppService = null): DaemonConfigService {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $value): string => 'encrypted:' . $value);
		// Ciphertexts in these tests are `encrypted:<iv>:<secret>`, so the same secret can have different ciphertexts
		$crypto->method('decrypt')->willReturnCallback(static fn (string $value): string => explode(':', $value, 3)[2] ?? $value);
		return new DaemonConfigService(
			$this->createMock(LoggerInterface::class),
			$mapper,
			$exAppService ?? $this->createMock(ExAppService::class),
			$crypto,
			$this->auditLogger,
		);
	}

	private static function harpRegisterParams(): array {
		return [
			'name' => 'harp_proxy',
			'display_name' => 'HaRP Proxy',
			'accepts_deploy_id' => 'docker-install',
			'protocol' => 'http',
			'host' => 'appapi-harp:8780',
			'deploy_config' => [
				'net' => 'host',
				'nextcloud_url' => 'http://nextcloud.local/',
				'haproxy_password' => 'a_secret_shared_key',
				'harp' => ['frp_address' => 'appapi-harp:8782'],
			],
		];
	}

	public function testRegisterDaemonConfigLogsTheDaemonWithoutTheSecret(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->expects(self::once())->method('insert')->willReturnArgument(0);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::DaemonRegistered,
			'Deploy daemon %s was registered',
			['daemonName' => 'harp_proxy', 'acceptsDeployId' => 'docker-install', 'host' => 'appapi-harp:8780'],
		);

		$result = $this->createServiceWithMapper($mapper)->registerDaemonConfig(self::harpRegisterParams());

		self::assertInstanceOf(DaemonConfig::class, $result);
	}

	public function testRegisterDaemonConfigDoesNotLogAnInvalidDaemon(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->expects(self::never())->method('insert');
		$this->auditLogger->expects(self::never())->method('log');

		$params = self::harpRegisterParams();
		$params['host'] = 'http://appapi-harp:8780';

		self::assertNull($this->createServiceWithMapper($mapper)->registerDaemonConfig($params));
	}

	public function testRegisterDaemonConfigDoesNotLogADatabaseError(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->method('insert')->willThrowException(new Exception('duplicate name'));
		$this->auditLogger->expects(self::never())->method('log');

		self::assertNull($this->createServiceWithMapper($mapper)->registerDaemonConfig(self::harpRegisterParams()));
	}

	public function testUnregisterDaemonConfigLogsTheDaemon(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->expects(self::once())->method('delete')->willReturnArgument(0);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::DaemonUnregistered,
			'Deploy daemon %s was unregistered',
			['daemonName' => 'harp_proxy'],
		);

		$this->createServiceWithMapper($mapper)->unregisterDaemonConfig(new DaemonConfig(['name' => 'harp_proxy']));
	}

	public function testUnregisterDaemonConfigDoesNotLogADatabaseError(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->method('delete')->willThrowException(new Exception('database is gone'));
		$this->auditLogger->expects(self::never())->method('log');

		self::assertNull($this->createServiceWithMapper($mapper)->unregisterDaemonConfig(new DaemonConfig(['name' => 'harp_proxy'])));
	}

	public static function updateDaemonConfigProvider(): array {
		$previous = ['host' => 'appapi-harp:8780', 'deploy_config' => ['net' => 'host', 'haproxy_password' => 'encrypted:iv1:old_secret']];
		return [
			'stored secret kept' => [$previous, $previous, null],
			'same secret entered again' => [$previous, ['deploy_config' => ['net' => 'host', 'haproxy_password' => 'encrypted:iv2:old_secret']] + $previous, null],
			'new secret set' => [$previous, ['deploy_config' => ['net' => 'host', 'haproxy_password' => 'encrypted:iv2:new_secret']] + $previous, 'deploy_config.haproxy_password'],
			'secret removed' => [$previous, ['deploy_config' => ['net' => 'host', 'haproxy_password' => '']] + $previous, 'deploy_config.haproxy_password'],
			'host and network changed' => [$previous, ['host' => 'harp:8780', 'deploy_config' => ['net' => 'bridge', 'haproxy_password' => 'encrypted:iv1:old_secret']], 'host, deploy_config.net'],
			'renamed' => [$previous, ['name' => 'harp_renamed'] + $previous, 'name', ['daemonName' => 'harp_renamed', 'previousName' => 'harp_proxy']],
			'port resent as a number' => [
				['deploy_config' => ['harp' => ['frp_address' => 'harp:8782', 'docker_socket_port' => '24000']]],
				['deploy_config' => ['harp' => ['docker_socket_port' => 24000, 'frp_address' => 'harp:8782']]],
				null,
			],
			'numeric string changed' => [['deploy_config' => ['net' => '01']], ['deploy_config' => ['net' => '1']], 'deploy_config.net'],
			'flag resent as a string' => [['deploy_config' => ['harp' => ['exapp_direct' => true]]], ['deploy_config' => ['harp' => ['exapp_direct' => '1']]], 'deploy_config.harp'],
		];
	}

	#[DataProvider('updateDaemonConfigProvider')]
	public function testUpdateDaemonConfigLogsTheNamesOfTheChangedSettings(array $previousParams, array $updatedParams, ?string $changedSettings, array $names = []): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->expects(self::once())->method('update')->willReturnArgument(0);
		if ($changedSettings === null) {
			$this->auditLogger->expects(self::never())->method('log');
		} else {
			$this->auditLogger->expects(self::once())->method('log')->with(
				Operation::DaemonUpdated,
				'Deploy daemon %s was updated',
				array_merge(['daemonName' => 'harp_proxy', 'changedSettings' => $changedSettings], $names),
			);
		}
		$previous = new DaemonConfig(['name' => 'harp_proxy'] + $previousParams);
		$updated = new DaemonConfig($updatedParams + ['name' => 'harp_proxy']);

		$this->createServiceWithMapper($mapper)->updateDaemonConfig($updated, $previous);
	}

	public function testUpdateDaemonConfigDoesNotLogADatabaseError(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->method('update')->willThrowException(new Exception('database is gone'));
		$this->auditLogger->expects(self::never())->method('log');
		$daemonConfig = new DaemonConfig(['name' => 'harp_proxy', 'deploy_config' => []]);

		self::assertNull($this->createServiceWithMapper($mapper)->updateDaemonConfig($daemonConfig, clone $daemonConfig));
	}

	public function testUpdateDaemonConfigResetsTheExAppsCache(): void {
		$mapper = $this->createMock(DaemonConfigMapper::class);
		$mapper->method('update')->willReturnArgument(0);
		$exAppService = $this->createMock(ExAppService::class);
		$exAppService->expects(self::once())->method('resetExAppsCache');
		$daemonConfig = new DaemonConfig(['name' => 'harp_proxy', 'deploy_config' => ['haproxy_password' => 'encrypted:iv2:new_secret']]);
		$previous = new DaemonConfig(['name' => 'harp_proxy', 'deploy_config' => ['haproxy_password' => 'encrypted:iv1:old_secret']]);

		$this->createServiceWithMapper($mapper, $exAppService)->updateDaemonConfig($daemonConfig, $previous);
	}

	public static function defaultDaemonProvider(): array {
		return [
			'set' => ['harp_proxy', 'AppAPI setting %s was changed to %s', ['key' => 'default_daemon_config', 'value' => 'harp_proxy']],
			'unset' => ['', 'AppAPI setting %s was deleted', ['key' => 'default_daemon_config']],
		];
	}

	#[DataProvider('defaultDaemonProvider')]
	public function testLogDefaultDaemonConfigChanged(string $daemonName, string $message, array $parameters): void {
		$this->auditLogger->expects(self::once())->method('log')->with(Operation::AdminConfigUpdated, $message, $parameters);

		$this->createServiceWithMapper($this->createMock(DaemonConfigMapper::class))->logDefaultDaemonConfigChanged($daemonName);
	}
}
