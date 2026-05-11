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
use OCP\IDBConnection;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Per-event admin choice on what to do with the orphaned image.
 */
enum ImageCleanupChoice: string {
	case GRACE = 'grace';      // Use the configured grace period (default).
	case PURGE_NOW = 'now';    // Skip the grace, delete immediately.
	case KEEP = 'keep';        // Don't schedule cleanup at all.
}

/**
 * Schedules cleanup of an ExApp's Docker image after uninstall or update.
 *
 * Two-step API: callers capture the ref *before* the container is removed,
 * then schedule cleanup *after*. Splitting the steps lets the caller sequence
 * around the actual `removeExApp` / `deployExApp` call without race conditions
 * between the inspect and the removal:
 *
 *     $ref = $cleanupService->captureImageRef($daemon, $appid);
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
		private readonly IDBConnection $db,
		private readonly DockerActions $dockerActions,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Capture the running container's image ref. Returns null if cleanup is
	 * disabled, the daemon isn't a Docker daemon, or the lookup failed.
	 *
	 * Call this *before* the container is removed.
	 */
	public function captureImageRef(DaemonConfig $daemonConfig, string $appid): ?string {
		if ($daemonConfig->getAcceptsDeployId() !== DockerActions::DEPLOY_ID) {
			return null;
		}
		if (!$this->isMasterEnabled()) {
			return null;
		}
		return $this->dockerActions->getRunningImageRef($daemonConfig, $appid);
	}

	/**
	 * Act on a previously captured ref. Schedules the orphan job for GRACE,
	 * deletes immediately for PURGE_NOW, or no-ops for KEEP / null ref.
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
		if ($imageRef === null || $imageRef === '') {
			return;
		}
		if ($choice === ImageCleanupChoice::KEEP) {
			return;
		}
		if (!$this->isMasterEnabled()) {
			return;
		}

		$argument = [
			'daemon_id' => $daemonConfig->getName(),
			'image_ref' => $imageRef,
			'appid' => $exApp->getAppid(),
		];

		if ($choice === ImageCleanupChoice::PURGE_NOW) {
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
	 * Cancel any pending OrphanedImageCleanupJob entries for the given daemon.
	 *
	 * Called on daemon unregister: the daemon (and its Docker socket) is going away,
	 * so future cleanup attempts on it would just fail. We walk the jobs table
	 * directly because IJobList has no by-argument query and our match needs to
	 * look inside the JSON-encoded argument column.
	 */
	public function cancelPendingForDaemon(string $daemonName): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'argument')
			->from('jobs')
			->where($qb->expr()->eq('class', $qb->createNamedParameter(OrphanedImageCleanupJob::class)));
		$result = $qb->executeQuery();
		$removed = 0;
		while ($row = $result->fetch()) {
			$arg = json_decode((string)$row['argument'], true);
			if (!is_array($arg) || ($arg['daemon_id'] ?? null) !== $daemonName) {
				continue;
			}
			$this->jobList->removeById((string)$row['id']);
			$removed++;
		}
		$result->closeCursor();
		if ($removed > 0) {
			$this->logger->info(sprintf(
				'ExAppImageCleanupService: cancelled %d pending image cleanup job(s) for daemon=%s.',
				$removed,
				$daemonName,
			));
		}
		return $removed;
	}

	private function isMasterEnabled(): bool {
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			Application::CONF_IMAGE_CLEANUP_ENABLED,
			(bool)Application::CONF_IMAGE_CLEANUP_DEFAULTS[Application::CONF_IMAGE_CLEANUP_ENABLED],
		);
	}

	private function resolvedGraceHours(): int {
		$value = $this->appConfig->getValueInt(
			Application::APP_ID,
			Application::CONF_IMAGE_CLEANUP_GRACE_HOURS,
			Application::CONF_IMAGE_CLEANUP_DEFAULTS[Application::CONF_IMAGE_CLEANUP_GRACE_HOURS],
		);
		return max(0, min(720, $value));
	}
}
