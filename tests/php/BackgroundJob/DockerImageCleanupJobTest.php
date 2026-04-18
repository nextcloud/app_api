<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\BackgroundJob;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\BackgroundJob\DockerImageCleanupJob;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DockerImageCleanupJobTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private DaemonConfigService&MockObject $daemonConfigService;
	private DockerActions&MockObject $dockerActions;
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;

	public function testRunSkipsWhenDisabled(): void {
		$job = $this->createJob(0);

		$this->daemonConfigService->expects(self::never())
			->method('getRegisteredDaemonConfigs');

		$this->dockerActions->expects(self::never())
			->method('pruneImages');

		$this->invokeRun($job);
	}

	public function testRunPrunesDockerDaemons(): void {
		$job = $this->createJob();

		$daemon = $this->createDaemonConfig('local-docker', DockerActions::DEPLOY_ID);

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$daemon]);

		$this->dockerActions->expects(self::once())
			->method('initGuzzleClient')
			->with($daemon);

		$this->dockerActions->expects(self::once())
			->method('buildDockerUrl')
			->with($daemon)
			->willReturn('http://localhost');

		$this->dockerActions->expects(self::once())
			->method('pruneImages')
			->with('http://localhost', ['dangling' => ['true']])
			->willReturn(['SpaceReclaimed' => 1048576, 'ImagesDeleted' => []]);

		$this->invokeRun($job);
	}

	public function testRunSkipsNonDockerDaemons(): void {
		$job = $this->createJob();

		$k8sDaemon = $this->createDaemonConfig('k8s-cluster', 'kubernetes-install');
		$manualDaemon = $this->createDaemonConfig('manual', 'manual-install');

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$k8sDaemon, $manualDaemon]);

		$this->dockerActions->expects(self::never())
			->method('pruneImages');

		$this->invokeRun($job);
	}

	public function testRunSkipsHarpDaemons(): void {
		$job = $this->createJob();

		$harpDaemon = $this->createDaemonConfig(
			'harp-daemon',
			DockerActions::DEPLOY_ID,
			['harp' => true]
		);

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$harpDaemon]);

		$this->dockerActions->expects(self::never())
			->method('pruneImages');

		$this->logger->expects(self::once())
			->method('debug')
			->with(self::stringContains('Skipping image prune for HaRP daemon'));

		$this->invokeRun($job);
	}

	public function testRunLogsErrorWhenPruneFails(): void {
		$job = $this->createJob();

		$daemon = $this->createDaemonConfig('local-docker', DockerActions::DEPLOY_ID);

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$daemon]);

		$this->dockerActions->method('buildDockerUrl')->willReturn('http://localhost');
		$this->dockerActions->method('pruneImages')
			->willReturn(['error' => 'Connection refused']);

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('Connection refused'));

		$this->invokeRun($job);
	}

	public function testRunCatchesExceptionsPerDaemon(): void {
		$job = $this->createJob();

		$daemon1 = $this->createDaemonConfig('failing-daemon', DockerActions::DEPLOY_ID);
		$daemon2 = $this->createDaemonConfig('working-daemon', DockerActions::DEPLOY_ID);

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$daemon1, $daemon2]);

		$this->dockerActions->method('buildDockerUrl')->willReturn('http://localhost');

		$callCount = 0;
		$this->dockerActions->method('pruneImages')
			->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					throw new \Exception('Daemon unreachable');
				}
				return ['SpaceReclaimed' => 0, 'ImagesDeleted' => null];
			});

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('Daemon unreachable'));

		$this->invokeRun($job);

		// Verify the second daemon was still processed
		self::assertSame(2, $callCount);
	}

	public function testRunPrunesMultipleDaemons(): void {
		$job = $this->createJob();

		$daemon1 = $this->createDaemonConfig('docker-1', DockerActions::DEPLOY_ID);
		$daemon2 = $this->createDaemonConfig('docker-2', DockerActions::DEPLOY_ID);

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$daemon1, $daemon2]);

		$this->dockerActions->method('buildDockerUrl')->willReturn('http://localhost');

		$this->dockerActions->expects(self::exactly(2))
			->method('pruneImages');

		$this->invokeRun($job);
	}

	public function testRunFiltersMixedDaemons(): void {
		$job = $this->createJob();

		$dockerDaemon = $this->createDaemonConfig('docker', DockerActions::DEPLOY_ID);
		$harpDaemon = $this->createDaemonConfig('harp', DockerActions::DEPLOY_ID, ['harp' => true]);
		$k8sDaemon = $this->createDaemonConfig('k8s', 'kubernetes-install');

		$this->daemonConfigService->method('getRegisteredDaemonConfigs')
			->willReturn([$dockerDaemon, $harpDaemon, $k8sDaemon]);

		$this->dockerActions->method('buildDockerUrl')->willReturn('http://localhost');

		// Only the plain Docker daemon should be pruned
		$this->dockerActions->expects(self::once())
			->method('pruneImages');

		$this->invokeRun($job);
	}

	private function createJob(int $intervalDays = 7): DockerImageCleanupJob {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(time());

		$this->appConfig->method('getValueInt')
			->willReturnCallback(function (string $appId, string $key, int $default) use ($intervalDays) {
				if ($key === Application::CONF_IMAGE_CLEANUP_INTERVAL_DAYS) {
					return $intervalDays;
				}
				return $default;
			});

		return new DockerImageCleanupJob(
			$this->timeFactory,
			$this->appConfig,
			$this->daemonConfigService,
			$this->dockerActions,
			$this->logger,
		);
	}

	private function createDaemonConfig(string $name, string $deployId, array $deployConfig = []): DaemonConfig {
		return new DaemonConfig([
			'name' => $name,
			'accepts_deploy_id' => $deployId,
			'deploy_config' => $deployConfig,
		]);
	}

	/**
	 * Invoke the protected run() method via reflection.
	 */
	private function invokeRun(DockerImageCleanupJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}
}
