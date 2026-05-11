<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\BackgroundJob\OrphanedImageCleanupJob;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Schedules cleanup of an ExApp's Docker image after uninstall or update.
 *
 * Two-step API: callers capture the ref *before* the container is removed,
 * then schedule cleanup *after*. Splitting the steps lets the caller sequence
 * around the actual `removeExApp` / `deployExApp` call without race conditions
 * between the inspect and the removal:
 *
 *     $ref = $cleanupService->captureImageRef($daemon, $appid, $choice);
 *     $this->dockerActions->removeExApp(...);     // container gone
 *     $cleanupService->scheduleCleanup($ref, $exApp, $daemon, $choice);
 *
 * No AppAPI state tracks "which image belongs to which ExApp"; the ref is
 * pulled fresh from Docker (via HaRP's extended /docker/exapp/exists) at
 * capture time. Docker is the source of truth.
 */
class ExAppImageCleanupService {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $timeFactory,
		private readonly DockerActions $dockerActions,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Capture the running container's image ref. Returns null if the daemon
	 * isn't a Docker daemon, the choice is KEEP (the ref would be discarded,
	 * so the HaRP round-trip is skipped), automatic cleanup is disabled while
	 * the choice is GRACE, or the lookup failed.
	 *
	 * The master toggle only governs the automatic (GRACE) path: an explicit
	 * PURGE_NOW from the admin is always honored.
	 *
	 * Never throws: image cleanup is best-effort and must not break the
	 * uninstall/update/daemon flows it hooks into.
	 *
	 * Call this *before* the container is removed.
	 */
	public function captureImageRef(
		DaemonConfig $daemonConfig,
		string $appid,
		ImageCleanupChoice $choice = ImageCleanupChoice::GRACE,
	): ?string {
		try {
			return $this->doCaptureImageRef($daemonConfig, $appid, $choice);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf(
				'ExAppImageCleanupService: failed to capture image ref for appid=%s daemon=%s: %s',
				$appid,
				$daemonConfig->getName(),
				$e->getMessage(),
			), ['exception' => $e]);
			return null;
		}
	}

	private function doCaptureImageRef(DaemonConfig $daemonConfig, string $appid, ImageCleanupChoice $choice): ?string {
		if ($choice === ImageCleanupChoice::KEEP) {
			return null;
		}
		if ($daemonConfig->getAcceptsDeployId() !== DockerActions::DEPLOY_ID) {
			return null;
		}
		if ($choice === ImageCleanupChoice::GRACE && !$this->isMasterEnabled()) {
			return null;
		}
		return $this->dockerActions->getRunningImageRef($daemonConfig, $appid);
	}

	/**
	 * Act on a previously captured ref. Schedules the orphan job for GRACE
	 * (when automatic cleanup is enabled), deletes immediately for PURGE_NOW,
	 * or cancels any still-pending cleanup of this app for KEEP.
	 *
	 * Never throws: see captureImageRef().
	 *
	 * Call this *after* the container is gone (so PURGE_NOW doesn't get a 409
	 * from Docker about an in-use image).
	 */
	public function scheduleCleanup(
		?string $imageRef,
		ExApp $exApp,
		DaemonConfig $daemonConfig,
		ImageCleanupChoice $choice = ImageCleanupChoice::GRACE,
	): void {
		try {
			$this->doScheduleCleanup($imageRef, $exApp, $daemonConfig, $choice);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf(
				'ExAppImageCleanupService: image cleanup failed for appid=%s daemon=%s; continuing without it: %s',
				$exApp->getAppid(),
				$daemonConfig->getName(),
				$e->getMessage(),
			), ['exception' => $e]);
		}
	}

	private function doScheduleCleanup(
		?string $imageRef,
		ExApp $exApp,
		DaemonConfig $daemonConfig,
		ImageCleanupChoice $choice,
	): void {
		if ($choice === ImageCleanupChoice::KEEP) {
			// "Keep" must also win over a job queued by an earlier uninstall or
			// update of the same app, or that stale job would delete the image
			// after the reinstall cycle finishes.
			$this->cancelPendingForApp($daemonConfig->getName(), $exApp->getAppid());
			return;
		}
		if ($imageRef === null || $imageRef === '') {
			if ($choice === ImageCleanupChoice::PURGE_NOW && $daemonConfig->getAcceptsDeployId() === DockerActions::DEPLOY_ID) {
				$this->logger->warning(sprintf(
					'ExAppImageCleanupService: immediate purge requested for appid=%s daemon=%s but no image ref could be captured; nothing was removed.',
					$exApp->getAppid(),
					$daemonConfig->getName(),
				));
			}
			return;
		}

		if ($choice === ImageCleanupChoice::PURGE_NOW) {
			// A grace job from an earlier orphan event for this app would only
			// find the image already gone; drop it instead of letting it no-op.
			$this->cancelPendingForApp($daemonConfig->getName(), $exApp->getAppid());
			$result = $this->dockerActions->removeImage($daemonConfig, $imageRef);
			if (($result['deleted'] ?? false) === true) {
				$this->logger->info(sprintf(
					'ExAppImageCleanupService: purged image=%s appid=%s daemon=%s (freed=%s).',
					$imageRef,
					$exApp->getAppid(),
					$daemonConfig->getName(),
					Util::humanFileSize((int)($result['bytes_freed'] ?? 0)),
				));
			} else {
				$this->logger->warning(sprintf(
					'ExAppImageCleanupService: --purge-now did not delete image=%s appid=%s daemon=%s reason=%s',
					$imageRef,
					$exApp->getAppid(),
					$daemonConfig->getName(),
					(string)($result['reason'] ?? 'unknown'),
				));
			}
			return;
		}

		// Only the automatic path is governed by the master toggle; explicit
		// PURGE_NOW and KEEP above are always honored.
		if (!$this->isMasterEnabled()) {
			return;
		}
		$argument = [
			'daemon_id' => $daemonConfig->getName(),
			'image_ref' => $imageRef,
			'appid' => $exApp->getAppid(),
		];
		$graceHours = $this->resolvedGraceHours();
		$runAfter = $this->timeFactory->getTime() + ($graceHours * 3600);
		$this->jobList->scheduleAfter(OrphanedImageCleanupJob::class, $runAfter, $argument);
		$this->logger->info(sprintf(
			'ExAppImageCleanupService: scheduled cleanup for image=%s appid=%s daemon=%s after %d hours.',
			$imageRef,
			$exApp->getAppid(),
			$daemonConfig->getName(),
			$graceHours,
		));
	}

	/**
	 * Purge the images of all pending OrphanedImageCleanupJob entries of the
	 * given daemon and drop the jobs.
	 *
	 * Called right before a daemon is unregistered: the grace period cannot be
	 * honored on a Docker host that is about to become unreachable, so this is
	 * the last chance to reclaim the space. Deletion stays best-effort (Docker
	 * 409 protects refs that were reinstalled in the meantime) and is skipped
	 * entirely when automatic cleanup is disabled.
	 *
	 * Never throws: see captureImageRef().
	 */
	public function flushPendingForDaemon(DaemonConfig $daemonConfig): int {
		try {
			return $this->doFlushPendingForDaemon($daemonConfig);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf(
				'ExAppImageCleanupService: failed to flush pending image cleanup jobs for daemon=%s: %s',
				$daemonConfig->getName(),
				$e->getMessage(),
			), ['exception' => $e]);
			return 0;
		}
	}

	private function doFlushPendingForDaemon(DaemonConfig $daemonConfig): int {
		if ($daemonConfig->getAcceptsDeployId() !== DockerActions::DEPLOY_ID) {
			return 0;
		}
		if (!$this->isMasterEnabled()) {
			return 0;
		}
		$flushed = 0;
		foreach ($this->jobList->getJobsIterator(OrphanedImageCleanupJob::class, null, 0) as $job) {
			$argument = $job->getArgument();
			if (!is_array($argument) || ($argument['daemon_id'] ?? null) !== $daemonConfig->getName()) {
				continue;
			}
			$imageRef = (string)($argument['image_ref'] ?? '');
			if ($imageRef !== '') {
				$result = $this->dockerActions->removeImage($daemonConfig, $imageRef);
				$this->logger->info(sprintf(
					'ExAppImageCleanupService: flushed pending cleanup of image=%s before daemon=%s unregister (deleted=%s, reason=%s).',
					$imageRef,
					$daemonConfig->getName(),
					($result['deleted'] ?? false) === true ? 'yes' : 'no',
					(string)($result['reason'] ?? 'ok'),
				));
			}
			$this->jobList->removeById($job->getId());
			$flushed++;
		}
		return $flushed;
	}

	/**
	 * Cancel any pending OrphanedImageCleanupJob entries for the given daemon.
	 *
	 * Called after daemon unregister: the daemon config is gone, so the queued
	 * jobs could never reach its Docker socket again.
	 *
	 * Never throws: see captureImageRef().
	 */
	public function cancelPendingForDaemon(string $daemonName): int {
		try {
			$removed = $this->cancelPendingMatching(
				static fn (array $argument): bool => ($argument['daemon_id'] ?? null) === $daemonName,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf(
				'ExAppImageCleanupService: failed to cancel pending image cleanup jobs for daemon=%s: %s',
				$daemonName,
				$e->getMessage(),
			), ['exception' => $e]);
			return 0;
		}
		if ($removed > 0) {
			$this->logger->info(sprintf(
				'ExAppImageCleanupService: cancelled %d pending image cleanup job(s) for daemon=%s.',
				$removed,
				$daemonName,
			));
		}
		return $removed;
	}

	/**
	 * Cancel any pending OrphanedImageCleanupJob entries for one app on one daemon.
	 *
	 * Never throws: see captureImageRef().
	 */
	public function cancelPendingForApp(string $daemonName, string $appid): int {
		try {
			$removed = $this->cancelPendingMatching(
				static fn (array $argument): bool => ($argument['daemon_id'] ?? null) === $daemonName
					&& ($argument['appid'] ?? null) === $appid,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf(
				'ExAppImageCleanupService: failed to cancel pending image cleanup jobs for appid=%s daemon=%s: %s',
				$appid,
				$daemonName,
				$e->getMessage(),
			), ['exception' => $e]);
			return 0;
		}
		if ($removed > 0) {
			$this->logger->info(sprintf(
				'ExAppImageCleanupService: cancelled %d pending image cleanup job(s) for appid=%s daemon=%s.',
				$removed,
				$appid,
				$daemonName,
			));
		}
		return $removed;
	}

	/**
	 * @param callable(array): bool $match
	 */
	private function cancelPendingMatching(callable $match): int {
		$removed = 0;
		foreach ($this->jobList->getJobsIterator(OrphanedImageCleanupJob::class, null, 0) as $job) {
			$argument = $job->getArgument();
			if (!is_array($argument) || !$match($argument)) {
				continue;
			}
			$this->jobList->removeById($job->getId());
			$removed++;
		}
		return $removed;
	}

	/**
	 * Single source of truth for the master toggle; Admin settings and all
	 * cleanup entry points must agree. The value is written lazy (admin UI),
	 * so it MUST be read with lazy: true: a non-lazy read in a fresh process
	 * (cron) silently returns the default instead of the stored value.
	 *
	 * Fails safe: when the setting cannot be read (e.g. a conflicting stored
	 * type), automatic deletion is treated as disabled.
	 */
	public function isMasterEnabled(): bool {
		try {
			return $this->appConfig->getValueBool(
				Application::APP_ID,
				Application::CONF_IMAGE_CLEANUP_ENABLED,
				Application::DEFAULT_IMAGE_CLEANUP_ENABLED,
				lazy: true,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ExAppImageCleanupService: could not read the image cleanup toggle; treating automatic cleanup as disabled: ' . $e->getMessage(),
				['exception' => $e],
			);
			return false;
		}
	}

	/**
	 * Single source of truth for the effective grace period, clamped to the
	 * supported range. See isMasterEnabled() for why lazy: true is required.
	 * Falls back to the default when the setting cannot be read.
	 */
	public function resolvedGraceHours(): int {
		try {
			$value = $this->appConfig->getValueInt(
				Application::APP_ID,
				Application::CONF_IMAGE_CLEANUP_GRACE_HOURS,
				Application::DEFAULT_IMAGE_CLEANUP_GRACE_HOURS,
				lazy: true,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ExAppImageCleanupService: could not read the image cleanup grace period; using the default: ' . $e->getMessage(),
				['exception' => $e],
			);
			$value = Application::DEFAULT_IMAGE_CLEANUP_GRACE_HOURS;
		}
		return max(0, min(Application::MAX_IMAGE_CLEANUP_GRACE_HOURS, $value));
	}
}
