<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

/**
 * Per-event admin choice on what to do with an orphaned ExApp Docker image.
 */
enum ImageCleanupChoice: string {
	case GRACE = 'grace';      // Use the configured grace period (default).
	case PURGE_NOW = 'now';    // Skip the grace, delete immediately.
	case KEEP = 'keep';        // Don't schedule cleanup at all.

	/**
	 * Map the mutually exclusive purge-now/keep CLI flag pair to a choice.
	 * Exclusivity of the two flags is validated by the command itself.
	 */
	public static function fromFlags(bool $purgeNow, bool $keepImage): self {
		return match (true) {
			$purgeNow => self::PURGE_NOW,
			$keepImage => self::KEEP,
			default => self::GRACE,
		};
	}
}
