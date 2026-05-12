<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Psalm stub for the spreed (Nextcloud Talk) bot event classes that AppAPI dispatches.
 * spreed itself is not in AppAPI's vendor tree, so psalm cannot otherwise resolve these.
 */

namespace OCA\Talk\Events;

use OCP\EventDispatcher\Event;

class BotInstallEvent extends Event {
	public function __construct(
		protected string $name,
		protected string $secret,
		protected string $url,
		protected string $description = '',
		protected ?int $features = null,
	) {
		parent::__construct();
	}
}

class BotUninstallEvent extends Event {
	public function __construct(
		protected string $secret,
		protected string $url,
	) {
		parent::__construct();
	}
}
