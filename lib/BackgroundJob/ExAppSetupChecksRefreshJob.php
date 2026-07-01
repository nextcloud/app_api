<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\Service\ExAppSetupCheckRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Baseline refresh of ExApp setup-check results, so they stay reasonably fresh even when nobody opens
 * the admin Overview (e.g. for `occ setupchecks` / monitoring). An admin visiting the page also fires
 * an immediate one-off refresh ({@see ExAppSetupChecksRefreshOnceJob}).
 */
class ExAppSetupChecksRefreshJob extends TimedJob {
	private const REFRESH_INTERVAL_SECONDS = 600; // 10 minutes

	public function __construct(
		ITimeFactory $time,
		private readonly ExAppSetupCheckRefreshService $refreshService,
	) {
		parent::__construct($time);
		$this->setInterval(self::REFRESH_INTERVAL_SECONDS);
	}

	protected function run($argument): void {
		$this->refreshService->refresh();
	}
}
