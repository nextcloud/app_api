<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\BackgroundJob;

use OCA\AppAPI\BackgroundJob\OrphanedImageCleanupJob;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrphanedImageCleanupJobTest extends TestCase {
	private DaemonConfigService&MockObject $daemonConfigService;
	private DockerActions&MockObject $dockerActions;
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $time;
	private OrphanedImageCleanupJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->job = new OrphanedImageCleanupJob(
			$this->time,
			$this->daemonConfigService,
			$this->dockerActions,
			$this->logger,
		);
	}

	private function invokeRun(array $argument): void {
		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($this->job, $argument);
	}

	private function makeDaemon(string $deployId = DockerActions::DEPLOY_ID, string $name = 'docker-harp'): DaemonConfig {
		$daemon = new DaemonConfig();
		$daemon->setName($name);
		$daemon->setAcceptsDeployId($deployId);
		return $daemon;
	}

	public function testMissingDaemonIdLogsWarningAndReturns(): void {
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('missing daemon_id or image_ref'));
		$this->daemonConfigService->expects(self::never())->method('getDaemonConfigByName');

		$this->invokeRun(['image_ref' => 'ghcr.io/foo/bar:1.0', 'appid' => 'demo']);
	}

	public function testMissingImageRefLogsWarningAndReturns(): void {
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('missing daemon_id or image_ref'));
		$this->daemonConfigService->expects(self::never())->method('getDaemonConfigByName');

		$this->invokeRun(['daemon_id' => 'docker-harp', 'appid' => 'demo']);
	}

	public function testUnknownDaemonLogsInfoAndReturns(): void {
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(null);
		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('no longer exists'));
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->invokeRun(['daemon_id' => 'gone', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}

	public function testNonDockerDaemonSkipsWithoutCallingRemove(): void {
		$daemon = $this->makeDaemon(KubernetesActions::DEPLOY_ID, 'k8s-test');
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemon);
		$this->dockerActions->expects(self::never())->method('removeImage');
		$this->logger->expects(self::atLeastOnce())->method('debug');

		$this->invokeRun(['daemon_id' => 'k8s-test', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}

	public function testSuccessfulDeleteLogsBytesFreed(): void {
		$daemon = $this->makeDaemon();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemon);
		$this->dockerActions->method('removeImage')->willReturn([
			'deleted' => true,
			'bytes_freed' => 1024 * 1024,
			'reason' => null,
		]);
		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('removed image'));

		$this->invokeRun(['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}

	public function testNotFoundResultLogsAlreadyGone(): void {
		$daemon = $this->makeDaemon();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemon);
		$this->dockerActions->method('removeImage')->willReturn([
			'deleted' => true,
			'bytes_freed' => 0,
			'reason' => 'not_found',
		]);
		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('already gone'));

		$this->invokeRun(['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}

	public function testInUseResultLogsLeavingInPlace(): void {
		$daemon = $this->makeDaemon();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemon);
		$this->dockerActions->method('removeImage')->willReturn([
			'deleted' => false,
			'bytes_freed' => 0,
			'reason' => 'in_use',
		]);
		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('still in use'));

		$this->invokeRun(['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}

	public function testErrorResultLogsWarning(): void {
		$daemon = $this->makeDaemon();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemon);
		$this->dockerActions->method('removeImage')->willReturn([
			'deleted' => false,
			'bytes_freed' => 0,
			'reason' => 'error',
		]);
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('failed to remove image'));

		$this->invokeRun(['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}

	public function testThrowableFromRemoveImageIsCaughtAndLogged(): void {
		$daemon = $this->makeDaemon();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemon);
		$this->dockerActions->method('removeImage')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('unexpected exception'));

		$this->invokeRun(['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'demo']);
	}
}
