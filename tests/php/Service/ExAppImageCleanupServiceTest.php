<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\BackgroundJob\OrphanedImageCleanupJob;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\ExAppImageCleanupService;
use OCA\AppAPI\Service\ImageCleanupChoice;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExAppImageCleanupServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IJobList&MockObject $jobList;
	private ITimeFactory&MockObject $timeFactory;
	private IDBConnection&MockObject $db;
	private DockerActions&MockObject $dockerActions;
	private LoggerInterface&MockObject $logger;
	private ExAppImageCleanupService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ExAppImageCleanupService(
			$this->appConfig,
			$this->jobList,
			$this->timeFactory,
			$this->db,
			$this->dockerActions,
			$this->logger,
		);
	}

	private function makeExApp(string $appid = 'demo'): ExApp {
		$exApp = new ExApp();
		$exApp->setAppid($appid);
		return $exApp;
	}

	private function makeDaemon(string $deployId = DockerActions::DEPLOY_ID, string $name = 'docker-harp'): DaemonConfig {
		$daemon = new DaemonConfig();
		$daemon->setName($name);
		$daemon->setAcceptsDeployId($deployId);
		return $daemon;
	}

	private function masterEnabled(bool $enabled): void {
		$this->appConfig->method('getValueBool')
			->with(Application::APP_ID, Application::CONF_IMAGE_CLEANUP_ENABLED, true)
			->willReturn($enabled);
	}

	private function graceHours(int $hours): void {
		$this->appConfig->method('getValueInt')
			->with(Application::APP_ID, Application::CONF_IMAGE_CLEANUP_GRACE_HOURS, 24)
			->willReturn($hours);
	}

	public function testCaptureImageRefSkipsNonDockerDaemons(): void {
		$daemon = $this->makeDaemon(KubernetesActions::DEPLOY_ID);
		$this->dockerActions->expects(self::never())->method('getRunningImageRef');

		self::assertNull($this->service->captureImageRef($daemon, 'demo'));
	}

	public function testCaptureImageRefSkipsWhenMasterDisabled(): void {
		$daemon = $this->makeDaemon();
		$this->masterEnabled(false);
		$this->dockerActions->expects(self::never())->method('getRunningImageRef');

		self::assertNull($this->service->captureImageRef($daemon, 'demo'));
	}

	public function testCaptureImageRefDelegatesToDockerActionsWhenEnabled(): void {
		$daemon = $this->makeDaemon();
		$this->masterEnabled(true);
		$this->dockerActions->expects(self::once())
			->method('getRunningImageRef')
			->with($daemon, 'demo')
			->willReturn('ghcr.io/foo/bar:1.0');

		self::assertSame('ghcr.io/foo/bar:1.0', $this->service->captureImageRef($daemon, 'demo'));
	}

	public function testScheduleCleanupNoOpsWhenRefIsNull(): void {
		$this->jobList->expects(self::never())->method('scheduleAfter');
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup(null, $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testScheduleCleanupNoOpsWhenRefIsEmptyString(): void {
		$this->jobList->expects(self::never())->method('scheduleAfter');
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup('', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testScheduleCleanupNoOpsForKeepChoice(): void {
		$this->jobList->expects(self::never())->method('scheduleAfter');
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::KEEP);
	}

	public function testScheduleCleanupNoOpsWhenMasterDisabled(): void {
		$this->masterEnabled(false);
		$this->jobList->expects(self::never())->method('scheduleAfter');
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testScheduleCleanupForGraceQueuesJobAfterConfiguredDelay(): void {
		$this->masterEnabled(true);
		$this->graceHours(24);
		$this->timeFactory->method('getTime')->willReturn(1000);

		$this->jobList->expects(self::once())
			->method('scheduleAfter')
			->with(
				OrphanedImageCleanupJob::class,
				1000 + (24 * 3600),
				[
					'daemon_id' => 'docker-harp',
					'image_ref' => 'ghcr.io/foo/bar:1.0',
					'appid' => 'demo',
				],
			);
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testScheduleCleanupClampsGraceToMaximum(): void {
		$this->masterEnabled(true);
		$this->graceHours(99999);
		$this->timeFactory->method('getTime')->willReturn(0);

		$this->jobList->expects(self::once())
			->method('scheduleAfter')
			->with(
				OrphanedImageCleanupJob::class,
				720 * 3600,
				self::anything(),
			);

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testScheduleCleanupClampsGraceToZero(): void {
		$this->masterEnabled(true);
		$this->graceHours(-5);
		$this->timeFactory->method('getTime')->willReturn(500);

		$this->jobList->expects(self::once())
			->method('scheduleAfter')
			->with(
				OrphanedImageCleanupJob::class,
				500,
				self::anything(),
			);

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testScheduleCleanupForPurgeNowDeletesImmediately(): void {
		$this->masterEnabled(true);
		$daemon = $this->makeDaemon();

		$this->dockerActions->expects(self::once())
			->method('removeImage')
			->with($daemon, 'ghcr.io/foo/bar:1.0')
			->willReturn(['deleted' => true, 'bytes_freed' => 1024, 'reason' => null]);
		$this->jobList->expects(self::never())->method('scheduleAfter');

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $daemon, ImageCleanupChoice::PURGE_NOW);
	}

	public function testScheduleCleanupForPurgeNowLogsWarningOnFailure(): void {
		$this->masterEnabled(true);
		$this->dockerActions->method('removeImage')
			->willReturn(['deleted' => false, 'bytes_freed' => 0, 'reason' => 'in_use']);
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('--purge-now did not delete'));

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::PURGE_NOW);
	}
}
