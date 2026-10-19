<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\AuditLog;

/**
 * Operations AppAPI writes to the admin audit log, in the form `app.entity.action`
 *
 * The values are matched by log consumers (filters, alert rules, SIEM imports),
 * so changing one is a breaking change for them. Case names may be renamed.
 */
enum Operation: string {
	// Deploy daemons
	case DaemonRegistered = 'app_api.daemon.registered';
	case DaemonUpdated = 'app_api.daemon.updated';
	case DaemonUnregistered = 'app_api.daemon.unregistered';
	case DaemonRegistryAdded = 'app_api.daemon_registry.added';
	case DaemonRegistryRemoved = 'app_api.daemon_registry.removed';

	/**
	 * Short description of when the operation is logged, used for the documentation
	 */
	public function description(): string {
		return match ($this) {
			self::DaemonRegistered => 'A deploy daemon that ExApps can be installed on was registered',
			self::DaemonUpdated => 'The configuration of a deploy daemon was changed',
			self::DaemonUnregistered => 'A deploy daemon was unregistered',
			self::DaemonRegistryAdded => 'A deploy daemon was set to pull the images of a Docker registry from another registry',
			self::DaemonRegistryRemoved => 'A Docker registry mapping was removed from a deploy daemon',
		};
	}
}
