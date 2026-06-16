<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Exceptions;

use Exception;
use OCP\AppFramework\Http;

/**
 * @package OCA\AppAPI\Exceptions
 */
class MaintenanceModeException extends Exception {
	public function __construct($message = 'Service unavailable while the server is in maintenance mode', $code = Http::STATUS_SERVICE_UNAVAILABLE) {
		parent::__construct($message, $code);
	}
}
