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
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExAppImageCleanupServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IJobList&MockObject $jobList;
	private ITimeFactory&MockObject $timeFactory;
	private DockerActions&MockObject $dockerActions;
	private LoggerInterface&MockObject $logger;
	private ExAppImageCleanupService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ExAppImageCleanupService(
			$this->appConfig,
			$this->jobList,
			$this->timeFactory,
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
			->with(Application::APP_ID, Application::CONF_IMAGE_CLEANUP_ENABLED, true, true)
			->willReturn($enabled);
	}

	private function graceHours(int $hours): void {
		$this->appConfig->method('getValueInt')
			->with(Application::APP_ID, Application::CONF_IMAGE_CLEANUP_GRACE_HOURS, 24, true)
			->willReturn($hours);
	}

	private function makeJob(string $id, array $argument): IJob&MockObject {
		$job = $this->createMock(IJob::class);
		$job->method('getId')->willReturn($id);
		$job->method('getArgument')->willReturn($argument);
		return $job;
	}

	public function testFromFlagsMapsFlagPairToChoice(): void {
		self::assertSame(ImageCleanupChoice::PURGE_NOW, ImageCleanupChoice::fromFlags(true, false));
		self::assertSame(ImageCleanupChoice::KEEP, ImageCleanupChoice::fromFlags(false, true));
		self::assertSame(ImageCleanupChoice::GRACE, ImageCleanupChoice::fromFlags(false, false));
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

	public function testCaptureImageRefSkipsHarpLookupForKeepChoice(): void {
		$daemon = $this->makeDaemon();
		$this->dockerActions->expects(self::never())->method('getRunningImageRef');

		self::assertNull($this->service->captureImageRef($daemon, 'demo', ImageCleanupChoice::KEEP));
	}

	public function testCaptureImageRefCapturesForPurgeNowEvenWhenMasterDisabled(): void {
		$daemon = $this->makeDaemon();
		$this->masterEnabled(false);
		$this->dockerActions->expects(self::once())
			->method('getRunningImageRef')
			->with($daemon, 'demo')
			->willReturn('ghcr.io/foo/bar:1.0');

		self::assertSame(
			'ghcr.io/foo/bar:1.0',
			$this->service->captureImageRef($daemon, 'demo', ImageCleanupChoice::PURGE_NOW),
		);
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

	public function testScheduleCleanupWarnsWhenPurgeNowHasNoRef(): void {
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('no image ref could be captured'));
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup(null, $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::PURGE_NOW);
	}

	public function testScheduleCleanupForKeepCancelsPendingJobsOfTheApp(): void {
		$matching = $this->makeJob('11', ['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'demo']);
		$otherApp = $this->makeJob('12', ['daemon_id' => 'docker-harp', 'image_ref' => 'r:2', 'appid' => 'other']);
		$otherDaemon = $this->makeJob('13', ['daemon_id' => 'another', 'image_ref' => 'r:1', 'appid' => 'demo']);
		$this->jobList->method('getJobsIterator')
			->with(OrphanedImageCleanupJob::class, null, 0)
			->willReturn([$matching, $otherApp, $otherDaemon]);
		$this->jobList->expects(self::once())->method('removeById')->with('11');
		$this->jobList->expects(self::never())->method('scheduleAfter');
		$this->dockerActions->expects(self::never())->method('removeImage');

		$this->service->scheduleCleanup('r:1', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::KEEP);
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
				Application::MAX_IMAGE_CLEANUP_GRACE_HOURS * 3600,
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
		$daemon = $this->makeDaemon();
		$this->jobList->method('getJobsIterator')->willReturn([]);

		$this->dockerActions->expects(self::once())
			->method('removeImage')
			->with($daemon, 'ghcr.io/foo/bar:1.0')
			->willReturn(['deleted' => true, 'bytes_freed' => 1024, 'reason' => null]);
		$this->jobList->expects(self::never())->method('scheduleAfter');

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $daemon, ImageCleanupChoice::PURGE_NOW);
	}

	public function testScheduleCleanupForPurgeNowRunsEvenWhenMasterDisabled(): void {
		$daemon = $this->makeDaemon();
		$this->masterEnabled(false);
		$this->jobList->method('getJobsIterator')->willReturn([]);

		$this->dockerActions->expects(self::once())
			->method('removeImage')
			->with($daemon, 'ghcr.io/foo/bar:1.0')
			->willReturn(['deleted' => true, 'bytes_freed' => 1024, 'reason' => null]);

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $daemon, ImageCleanupChoice::PURGE_NOW);
	}

	public function testScheduleCleanupForPurgeNowCancelsStalePendingJob(): void {
		$daemon = $this->makeDaemon();
		$stale = $this->makeJob('21', ['daemon_id' => 'docker-harp', 'image_ref' => 'r:0', 'appid' => 'demo']);
		$this->jobList->method('getJobsIterator')->willReturn([$stale]);
		$this->jobList->expects(self::once())->method('removeById')->with('21');
		$this->dockerActions->method('removeImage')
			->willReturn(['deleted' => true, 'bytes_freed' => 0, 'reason' => null]);

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $daemon, ImageCleanupChoice::PURGE_NOW);
	}

	public function testScheduleCleanupForPurgeNowLogsWarningOnFailure(): void {
		$this->jobList->method('getJobsIterator')->willReturn([]);
		$this->dockerActions->method('removeImage')
			->willReturn(['deleted' => false, 'bytes_freed' => 0, 'reason' => 'in_use']);
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('--purge-now did not delete'));

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::PURGE_NOW);
	}

	public function testCancelPendingForDaemonRemovesOnlyMatchingJobs(): void {
		$matching1 = $this->makeJob('31', ['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'a']);
		$matching2 = $this->makeJob('32', ['daemon_id' => 'docker-harp', 'image_ref' => 'r:2', 'appid' => 'b']);
		$other = $this->makeJob('33', ['daemon_id' => 'another', 'image_ref' => 'r:3', 'appid' => 'c']);
		$this->jobList->method('getJobsIterator')
			->with(OrphanedImageCleanupJob::class, null, 0)
			->willReturn([$matching1, $matching2, $other]);
		$removed = [];
		$this->jobList->method('removeById')
			->willReturnCallback(function (string $id) use (&$removed): void {
				$removed[] = $id;
			});

		self::assertSame(2, $this->service->cancelPendingForDaemon('docker-harp'));
		self::assertSame(['31', '32'], $removed);
	}

	public function testFlushPendingForDaemonPurgesMatchingImagesAndDropsJobs(): void {
		$daemon = $this->makeDaemon();
		$this->masterEnabled(true);
		$matching = $this->makeJob('51', ['daemon_id' => 'docker-harp', 'image_ref' => 'r:1', 'appid' => 'a']);
		$other = $this->makeJob('52', ['daemon_id' => 'another', 'image_ref' => 'r:2', 'appid' => 'b']);
		$this->jobList->method('getJobsIterator')->willReturn([$matching, $other]);
		$this->dockerActions->expects(self::once())
			->method('removeImage')
			->with($daemon, 'r:1')
			->willReturn(['deleted' => true, 'bytes_freed' => 10, 'reason' => null]);
		$this->jobList->expects(self::once())->method('removeById')->with('51');

		self::assertSame(1, $this->service->flushPendingForDaemon($daemon));
	}

	public function testFlushPendingForDaemonSkipsWhenMasterDisabled(): void {
		$this->masterEnabled(false);
		$this->jobList->expects(self::never())->method('getJobsIterator');
		$this->dockerActions->expects(self::never())->method('removeImage');

		self::assertSame(0, $this->service->flushPendingForDaemon($this->makeDaemon()));
	}

	public function testFlushPendingForDaemonSkipsNonDockerDaemons(): void {
		$this->dockerActions->expects(self::never())->method('removeImage');

		self::assertSame(0, $this->service->flushPendingForDaemon($this->makeDaemon(KubernetesActions::DEPLOY_ID)));
	}

	public function testCancelPendingForDaemonIgnoresMalformedArguments(): void {
		$malformed = $this->makeJob('41', []);
		$this->jobList->method('getJobsIterator')->willReturn([$malformed]);
		$this->jobList->expects(self::never())->method('removeById');

		self::assertSame(0, $this->service->cancelPendingForDaemon('docker-harp'));
	}

	public function testCaptureImageRefNeverThrows(): void {
		$this->masterEnabled(true);
		$this->dockerActions->method('getRunningImageRef')
			->willThrowException(new \RuntimeException('daemon exploded'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('failed to capture image ref'));

		self::assertNull($this->service->captureImageRef($this->makeDaemon(), 'demo'));
	}

	public function testScheduleCleanupNeverThrows(): void {
		$this->masterEnabled(true);
		$this->graceHours(24);
		$this->timeFactory->method('getTime')->willReturn(0);
		$this->jobList->method('scheduleAfter')
			->willThrowException(new \RuntimeException('jobs table unavailable'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('image cleanup failed'));

		$this->service->scheduleCleanup('ghcr.io/foo/bar:1.0', $this->makeExApp(), $this->makeDaemon(), ImageCleanupChoice::GRACE);
	}

	public function testCancelPendingForDaemonNeverThrows(): void {
		$this->jobList->method('getJobsIterator')
			->willThrowException(new \RuntimeException('jobs table unavailable'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('failed to cancel pending'));

		self::assertSame(0, $this->service->cancelPendingForDaemon('docker-harp'));
	}

	public function testIsMasterEnabledFailsSafeToDisabled(): void {
		$this->appConfig->method('getValueBool')
			->willThrowException(new \RuntimeException('type conflict'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('treating automatic cleanup as disabled'));

		self::assertFalse($this->service->isMasterEnabled());
	}

	public function testResolvedGraceHoursFallsBackToDefaultOnReadFailure(): void {
		$this->appConfig->method('getValueInt')
			->willThrowException(new \RuntimeException('type conflict'));

		self::assertSame(Application::DEFAULT_IMAGE_CLEANUP_GRACE_HOURS, $this->service->resolvedGraceHours());
	}
}
