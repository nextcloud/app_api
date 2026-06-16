<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Attribute;

use Attribute;

/**
 * Marks a controller method that stays reachable while the server is in maintenance mode.
 *
 * @since 35.0.0
 */
#[Attribute]
class MaintenanceModeAvailable {
}
