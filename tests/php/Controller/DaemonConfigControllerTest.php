<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\DaemonConfigController;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DaemonConfigControllerTest extends TestCase {

	private DaemonConfigController $controller;
	private DaemonConfigService&MockObject $daemonConfigService;
	private ICrypto&MockObject $crypto;
	private AppAPIService&MockObject $service;
	private ExAppService&MockObject $exAppService;
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $value): string => 'encrypted:' . $value);
		$this->crypto->method('decrypt')->willReturnCallback(static fn (string $value): string => substr($value, strlen('encrypted:')));
		$this->service = $this->createMock(AppAPIService::class);
		$this->exAppService = $this->createMock(ExAppService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->controller = new DaemonConfigController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->daemonConfigService,
			$this->createMock(DockerActions::class),
			$this->createMock(KubernetesActions::class),
			$this->service,
			$this->exAppService,
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
		$storedDaemon = self::storedDaemon('encrypted:stored_secret_1', true);
		$this->daemonConfigService->method('getDaemonConfigByName')
			->willReturn($storedDaemon);
		$this->daemonConfigService->expects(self::once())->method('validateNewSecret')->willReturn(null);
		$this->daemonConfigService->expects(self::once())->method('updateDaemonConfig')
			->with(self::callback(static fn (DaemonConfig $daemonConfig): bool
				=> $daemonConfig->getDeployConfig()['haproxy_password'] === 'encrypted:a_long_enough_key'), self::identicalTo($storedDaemon))
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

	public function testStopTestDeployLogsTheRemoval(): void {
		$exApp = new ExApp(['appid' => 'test-deploy', 'daemon_config_name' => 'harp_proxy_host']);
		$this->exAppService->method('getExApp')->willReturnOnConsecutiveCalls($exApp, null, null);
		$this->exAppService->expects(self::once())->method('logExAppUninstallRequested')->with($exApp);
		$this->service->expects(self::once())->method('runOccCommand')->willReturn(true);

		$this->controller->stopTestDeploy('harp_proxy_host');
	}

	public function testStartTestDeployLogsTheInstallation(): void {
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(new DaemonConfig(['name' => 'harp_proxy_host']));
		$this->exAppService->expects(self::once())->method('logExAppInstallRequested')->with('test-deploy', 'harp_proxy_host');
		$this->service->expects(self::once())->method('runOccCommand')->willReturn(false);

		$this->controller->startTestDeploy('harp_proxy_host');
	}

	public static function registerDefaultDaemonProvider(): array {
		return [
			'new default daemon' => [true, true, true],
			'already the default daemon' => [true, false, false],
			'not the default daemon' => [false, false, false],
		];
	}

	#[DataProvider('registerDefaultDaemonProvider')]
	public function testRegisterLogsANewDefaultDaemon(bool $defaultDaemon, bool $valueChanged, bool $logged): void {
		$params = self::harpDaemonParams('a_valid_shared_key');
		$this->daemonConfigService->method('validateNewSecret')->willReturn(null);
		$this->daemonConfigService->method('registerDaemonConfig')->willReturn(new DaemonConfig($params));
		$this->appConfig->method('setValueString')->willReturn($valueChanged);
		$this->daemonConfigService->expects($logged ? self::once() : self::never())
			->method('logDefaultDaemonConfigChanged')->with('harp_proxy_host');

		$this->controller->registerDaemonConfig($params, $defaultDaemon);
	}

	public function testUnregisterLogsTheRemovedDefaultDaemon(): void {
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(new DaemonConfig(['name' => 'harp_proxy_host']));
		$this->appConfig->method('getValueString')->willReturn('harp_proxy_host');
		$this->appConfig->expects(self::once())->method('deleteKey')->with('app_api', 'default_daemon_config');
		$this->daemonConfigService->expects(self::once())->method('logDefaultDaemonConfigChanged')->with('');

		$this->controller->unregisterDaemonConfig('harp_proxy_host');
	}
}
