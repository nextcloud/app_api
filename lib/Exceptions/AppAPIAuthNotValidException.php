<?php

declare(strict_types=1);

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
