<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Attribute;

use Attribute;

/**
 * Attribute for controller methods that requires App Ecosystem V2 authentication.
 *
 * @since 27.0.0
 */
#[Attribute]
class AppEcosystemAuth {
}
