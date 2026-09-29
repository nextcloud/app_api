<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\DaemonConfigController;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DaemonConfigControllerTest extends TestCase {

	private DaemonConfigController $controller;
	private DaemonConfigService&MockObject $daemonConfigService;
	private ICrypto&MockObject $crypto;

	protected function setUp(): void {
		parent::setUp();

		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $value): string => 'encrypted:' . $value);
		$this->crypto->method('decrypt')->willReturnCallback(static fn (string $value): string => substr($value, strlen('encrypted:')));

		$this->controller = new DaemonConfigController(
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->daemonConfigService,
			$this->createMock(DockerActions::class),
			$this->createMock(KubernetesActions::class),
			$this->createMock(AppAPIService::class),
			$this->createMock(ExAppService::class),
			$this->createMock(IL10N::class),
			$this->crypto,
		);
	}

	private static function harpDaemonParams(string $secret): array {
		return [
			'name' => 'harp_proxy_host',
			'display_name' => 'HaRP Proxy (Host)',
			'accepts_deploy_id' => 'docker-install',
			'protocol' => 'http',
			'host' => 'localhost:8780',
			'deploy_config' => [
				'net' => 'host',
				'nextcloud_url' => 'http://nextcloud.local',
				'haproxy_password' => $secret,
				'harp' => ['frp_address' => 'localhost:8782'],
			],
		];
	}

	private static function storedDaemon(string $storedSecret, bool $isHarp): DaemonConfig {
		return new DaemonConfig([
			'name' => 'harp_proxy_host',
			'protocol' => 'http',
			'deploy_config' => [
				'haproxy_password' => $storedSecret,
				'harp' => $isHarp ? ['frp_address' => 'localhost:8782'] : null,
			],
		]);
	}

	public function testRegisterRejectsAnInvalidSecret(): void {
		$params = self::harpDaemonParams('short');
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')
			->with($params, 'short')->willReturn('The HaRP shared key must be at least 12 characters long.');
		$this->daemonConfigService->expects(self::never())->method('registerDaemonConfig');

		$response = $this->controller->registerDaemonConfig($params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['success' => false, 'daemonConfig' => null], $response->getData());
	}

	public function testRegisterValidatesTheSecretBeforeRegistering(): void {
		$params = self::harpDaemonParams('a_long_enough_key');
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')
			->with($params, 'a_long_enough_key')->willReturn(null);
		$this->daemonConfigService->expects(self::once())->method('registerDaemonConfig')
			->with($params)->willReturn(new DaemonConfig(['name' => 'harp_proxy_host']));

		$response = $this->controller->registerDaemonConfig($params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertTrue($response->getData()['success']);
	}

	public function testUpdateRejectsAnInvalidNewSecret(): void {
		$params = self::harpDaemonParams('short');
		$this->daemonConfigService->method('getDaemonConfigByName')
			->willReturn(self::storedDaemon('encrypted:stored_secret_1', true));
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')
			->with($params, 'short')->willReturn('The HaRP shared key must be at least 12 characters long.');
		$this->daemonConfigService->expects(self::never())->method('updateDaemonConfig');

		$response = $this->controller->updateDaemonConfig('harp_proxy_host', $params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['success' => false, 'daemonConfig' => null], $response->getData());
	}

	public function testUpdateEncryptsAValidNewSecret(): void {
		$params = self::harpDaemonParams('a_long_enough_key');
		$this->daemonConfigService->method('getDaemonConfigByName')
			->willReturn(self::storedDaemon('encrypted:stored_secret_1', true));
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')->willReturn(null);
		$this->daemonConfigService->expects(self::once())->method('updateDaemonConfig')
			->with(self::callback(static fn (DaemonConfig $daemonConfig): bool
				=> $daemonConfig->getDeployConfig()['haproxy_password'] === 'encrypted:a_long_enough_key'))
			->willReturnArgument(0);

		$response = $this->controller->updateDaemonConfig('harp_proxy_host', $params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertTrue($response->getData()['success']);
	}

	public function testUpdateKeepsTheStoredSecretOfADaemonThatAlreadyNeedsOne(): void {
		$params = self::harpDaemonParams('dummySecret123');
		$this->daemonConfigService->method('getDaemonConfigByName')
			->willReturn(self::storedDaemon('encrypted:stored_secret_1', true));
		$this->daemonConfigService->expects(self::never())->method('validateNewSecret');
		$this->daemonConfigService->expects(self::once())->method('updateDaemonConfig')
			->with(self::callback(static fn (DaemonConfig $daemonConfig): bool
				=> $daemonConfig->getDeployConfig()['haproxy_password'] === 'encrypted:stored_secret_1'))
			->willReturnArgument(0);

		$response = $this->controller->updateDaemonConfig('harp_proxy_host', $params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertTrue($response->getData()['success']);
	}

	public function testUpdateChecksTheStoredSecretWhenTheDaemonStartsToNeedOne(): void {
		$params = self::harpDaemonParams('dummySecret123');
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(self::storedDaemon('encrypted:abc', false));
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')
			->with($params, 'abc')->willReturn('The HaRP shared key must be at least 12 characters long.');
		$this->daemonConfigService->expects(self::never())->method('updateDaemonConfig');

		$response = $this->controller->updateDaemonConfig('harp_proxy_host', $params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['success' => false, 'daemonConfig' => null], $response->getData());
	}

	public function testUpdateKeepsAValidStoredSecretWhenTheDaemonStartsToNeedOne(): void {
		$params = self::harpDaemonParams('dummySecret123');
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(self::storedDaemon('encrypted:a_long_enough_key', false));
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')
			->with($params, 'a_long_enough_key')->willReturn(null);
		$this->daemonConfigService->expects(self::once())->method('updateDaemonConfig')
			->with(self::callback(static fn (DaemonConfig $daemonConfig): bool
				=> $daemonConfig->getDeployConfig()['haproxy_password'] === 'encrypted:a_long_enough_key'))
			->willReturnArgument(0);

		$response = $this->controller->updateDaemonConfig('harp_proxy_host', $params);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertTrue($response->getData()['success']);
	}

	public function testUpdateOfAnUnknownDaemonFails(): void {
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(null);
		$this->daemonConfigService->expects(self::never())->method('updateDaemonConfig');

		$response = $this->controller->updateDaemonConfig('unknown', self::harpDaemonParams('dummySecret123'));

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(['success' => false, 'daemonConfig' => null], $response->getData());
	}
}
