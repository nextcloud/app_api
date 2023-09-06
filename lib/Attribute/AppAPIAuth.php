<?php

declare(strict_types=1);

namespace OCA\AppApi\Attribute;

use Attribute;

/**
 * Attribute for controller methods that requires AppAPI authentication.
 *
 * @since 27.1.0
 */
#[Attribute]
class AppAPIAuth {
}
