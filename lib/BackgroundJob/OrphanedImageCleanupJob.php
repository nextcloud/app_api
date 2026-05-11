<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Removes a Docker image that was orphaned by an ExApp uninstall or update.
 *
 * Scheduled via IJobList::scheduleAfter when an ExApp is removed (or updated to a new image).
 * The grace period (default 24h) is configured globally; it gives a window for reinstall/rollback
 * before the image is reclaimed. Docker's own DELETE-with-409 behaviour is the source of truth
 * for "is the image still in use" — if anything (a fresh container, a stopped container, another
 * ExApp using the same image) still references the ref, Docker refuses and the job logs and exits.
 *
 * QueuedJob handles self-removal automatically: the entry is removed from the job queue before
 * run() is called, so a failure here is not retried by re-queuing. The next orphan event (or a
 * manual --purge-now from the admin) is the next opportunity.
 */
class OrphanedImageCleanupJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly DockerActions $dockerActions,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!is_array($argument)) {
			$this->logger->warning('OrphanedImageCleanupJob received non-array argument; skipping.');
			return;
		}
		$daemonName = (string)($argument['daemon_id'] ?? '');
		$imageRef = (string)($argument['image_ref'] ?? '');
		$appid = (string)($argument['appid'] ?? '');

		if ($daemonName === '' || $imageRef === '') {
			$this->logger->warning('OrphanedImageCleanupJob missing daemon_id or image_ref in argument; skipping.', [
				'argument' => $argument,
			]);
			return;
		}

		$daemon = $this->daemonConfigService->getDaemonConfigByName($daemonName);
		if ($daemon === null) {
			$this->logger->info(sprintf(
				'OrphanedImageCleanupJob: daemon "%s" no longer exists; skipping cleanup of image "%s" (appid=%s).',
				$daemonName,
				$imageRef,
				$appid,
			));
			return;
		}

		if ($daemon->getAcceptsDeployId() !== DockerActions::DEPLOY_ID) {
			// Kubernetes / Manual: image lifecycle isn't AppAPI's responsibility.
			$this->logger->debug(sprintf(
				'OrphanedImageCleanupJob: daemon "%s" is not a Docker daemon (deploy_id=%s); skipping image "%s" (appid=%s).',
				$daemonName,
				$daemon->getAcceptsDeployId(),
				$imageRef,
				$appid,
			));
			return;
		}

		try {
			$result = $this->dockerActions->removeImage($daemon, $imageRef);
		} catch (\Throwable $e) {
			$this->logger->error(sprintf(
				'OrphanedImageCleanupJob: unexpected exception removing image "%s" on daemon "%s" (appid=%s): %s',
				$imageRef,
				$daemonName,
				$appid,
				$e->getMessage(),
			), ['exception' => $e]);
			return;
		}

		if ($result['deleted'] === true) {
			if (($result['reason'] ?? null) === 'not_found') {
				$this->logger->info(sprintf(
					'OrphanedImageCleanupJob: image "%s" already gone on daemon "%s" (appid=%s).',
					$imageRef,
					$daemonName,
					$appid,
				));
				return;
			}
			$this->logger->info(sprintf(
				'OrphanedImageCleanupJob: removed image "%s" on daemon "%s" (appid=%s, freed=%s).',
				$imageRef,
				$daemonName,
				$appid,
				Util::humanFileSize((int)($result['bytes_freed'] ?? 0)),
			));
			return;
		}

		$reason = (string)($result['reason'] ?? 'unknown');
		if ($reason === 'in_use') {
			$this->logger->info(sprintf(
				'OrphanedImageCleanupJob: image "%s" still in use on daemon "%s" (appid=%s); leaving it in place.',
				$imageRef,
				$daemonName,
				$appid,
			));
			return;
		}
		$this->logger->warning(sprintf(
			'OrphanedImageCleanupJob: failed to remove image "%s" on daemon "%s" (appid=%s, reason=%s).',
			$imageRef,
			$daemonName,
			$appid,
			$reason,
		));
	}
}
