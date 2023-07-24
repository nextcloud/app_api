<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Exceptions;

use OCP\AppFramework\Http;

/**
 * @package OCA\AppEcosystemV2\Exceptions
 */
class AEAuthNotValidException extends \Exception {
	public function __construct($message = 'AEAuth failed', $code = Http::STATUS_UNAUTHORIZED) {
		parent::__construct($message, $code);
	}
}
