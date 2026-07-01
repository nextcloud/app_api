<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\Service\ExAppSetupCheckRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * One-off refresh of ExApp setup-check results, enqueued by {@see \OCA\AppAPI\SetupChecks\ExAppsSetupCheck}
 * when an admin opens the "Security & setup warnings" page (stale-while-revalidate). Enqueued via
 * IJobList::add(), which is idempotent, so visiting repeatedly never piles up duplicate jobs.
 */
class ExAppSetupChecksRefreshOnceJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly ExAppSetupCheckRefreshService $refreshService,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$this->refreshService->refresh();
	}
}
