<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Attribute;

use Attribute;

/**
 * Attribute for controller methods that requires AppAPI authentication.
 *
 * @since 27.1.0
 */
#[Attribute]
class AppAPIAuth {
}
