<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Exceptions;

use Exception;
use OCP\AppFramework\Http;

/**
 * @package OCA\AppAPI\Exceptions
 */
class AppAPIAuthNotValidException extends Exception {
	public function __construct($message = 'AppAPIAuth failed', $code = Http::STATUS_UNAUTHORIZED) {
		parent::__construct($message, $code);
	}
}
