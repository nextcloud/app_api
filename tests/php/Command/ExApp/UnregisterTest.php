<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Command\ExApp;

use OCA\AppAPI\Command\ExApp\Unregister;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class UnregisterTest extends TestCase {
	private DaemonConfigService&MockObject $daemonConfigService;
	private DockerActions&MockObject $dockerActions;
	private KubernetesActions&MockObject $kubernetesActions;
	private ExAppService&MockObject $exAppService;
	private LoggerInterface&MockObject $logger;
	private CommandTester $commandTester;

	protected function setUp(): void {
		parent::setUp();

		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->dockerActions->method('getAcceptsDeployId')->willReturn('docker-install');
		$this->dockerActions->method('buildExAppContainerName')->willReturnCallback(static fn (string $appId): string => 'nc_app_' . $appId);
		$this->kubernetesActions = $this->createMock(KubernetesActions::class);
		$this->kubernetesActions->method('getAcceptsDeployId')->willReturn('kubernetes-install');
		$this->exAppService = $this->createMock(ExAppService::class);
		$this->exAppService->method('unregisterExApp')->willReturn(true);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->commandTester = new CommandTester(new Unregister(
			$this->createMock(AppAPIService::class),
			$this->daemonConfigService,
			$this->dockerActions,
			$this->kubernetesActions,
			$this->exAppService,
			$this->createMock(ExAppDeployOptionsService::class),
			$this->logger,
		));
	}

	private function registerExApp(string $acceptsDeployId, bool $isHarp): ExApp {
		$exApp = new ExApp([
			'appid' => 'test_app',
			'daemon_config_name' => 'test_daemon',
			'enabled' => 0,
		]);
		$deployConfig = $isHarp ? ['harp' => ['frp_address' => 'appapi-harp:8782']] : [];
		$exApp->setDeployConfig($deployConfig);
		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->daemonConfigService->method('getDaemonConfigByName')
			->willReturn(new DaemonConfig(['name' => 'test_daemon', 'accepts_deploy_id' => $acceptsDeployId, 'deploy_config' => $deployConfig]));
		return $exApp;
	}

	public static function harpProvider(): array {
		return [
			'removed with its data' => [true, '', true],
			'removed without its data' => [false, '', false],
			'removal failed' => [true, 'Failed to remove ExApp', false],
		];
	}

	#[DataProvider('harpProvider')]
	public function testHarpExAppDataDeletionIsLogged(bool $rmData, string $removeError, bool $logged): void {
		$exApp = $this->registerExApp('docker-install', true);
		$this->dockerActions->method('removeExApp')->willReturn($removeError);
		$this->exAppService->expects($logged ? self::once() : self::never())->method('logExAppDataDeleted')->with($exApp);

		$this->commandTester->execute(['appid' => 'test_app', '--rm-data' => $rmData, '--force' => true, '--silent' => true]);
	}

	public function testDockerSocketProxyExAppIsUnregisteredWithoutItsContainer(): void {
		$this->registerExApp('docker-install', false);
		$this->dockerActions->expects(self::never())->method('initGuzzleClient');
		$this->dockerActions->expects(self::never())->method('removeExApp');
		$this->exAppService->expects(self::never())->method('logExAppDataDeleted');
		$this->exAppService->expects(self::once())->method('unregisterExApp')->with('test_app');
		$message = 'Daemon "test_daemon" uses Docker Socket Proxy, which is no longer supported, so container nc_app_test_app and its data volume are kept. Install the ExApp on a HaRP daemon on the same Docker host to reuse the data, or remove the container by hand.';
		$this->logger->expects(self::once())->method('warning')->with($message);

		self::assertSame(0, $this->commandTester->execute(['appid' => 'test_app', '--rm-data' => true, '--silent' => true]));
		self::assertSame('', $this->commandTester->getDisplay());
	}

	public static function kubernetesProvider(): array {
		return [
			'removed with its data' => [true, '', true],
			'removed without its data' => [false, '', false],
			'removal failed' => [true, 'Failed to remove K8s ExApp', false],
		];
	}

	#[DataProvider('kubernetesProvider')]
	public function testKubernetesExAppDataDeletionIsLogged(bool $rmData, string $removeError, bool $logged): void {
		$exApp = $this->registerExApp('kubernetes-install', true);
		$this->kubernetesActions->method('removeExApp')->willReturn($removeError);
		$this->exAppService->expects($logged ? self::once() : self::never())->method('logExAppDataDeleted')->with($exApp);

		$this->commandTester->execute(['appid' => 'test_app', '--rm-data' => $rmData, '--force' => true, '--silent' => true]);
	}
}
