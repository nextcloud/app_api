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
	// ExApps
	case ExAppRegistered = 'app_api.ex_app.registered';
	case ExAppUpdated = 'app_api.ex_app.updated';
	case ExAppEnabled = 'app_api.ex_app.enabled';
	case ExAppDisabled = 'app_api.ex_app.disabled';
	case ExAppUnregistered = 'app_api.ex_app.unregistered';
	case ExAppDataDeleted = 'app_api.ex_app_data.deleted';
	case ExAppAuthFailed = 'app_api.ex_app_auth.failed';

	// Deploy daemons
	case DaemonRegistered = 'app_api.daemon.registered';
	case DaemonUpdated = 'app_api.daemon.updated';
	case DaemonUnregistered = 'app_api.daemon.unregistered';
	case DaemonRegistryAdded = 'app_api.daemon_registry.added';
	case DaemonRegistryRemoved = 'app_api.daemon_registry.removed';

	// Settings
	case AdminConfigUpdated = 'app_api.admin_config.updated';

	/**
	 * Short description of when the operation is logged, used for the documentation
	 */
	public function description(): string {
		return match ($this) {
			self::ExAppRegistered => 'An ExApp was registered and given its secret to authenticate to Nextcloud, before its deployment',
			self::ExAppUpdated => 'An ExApp was switched to a new version, before the deployment of that version',
			self::ExAppEnabled => 'An ExApp was enabled and started',
			self::ExAppDisabled => 'An ExApp was disabled',
			self::ExAppUnregistered => 'An ExApp was removed together with everything it had registered',
			self::ExAppDataDeleted => 'The data volume of an ExApp was deleted on its deploy daemon',
			self::ExAppAuthFailed => 'A request with AppAPI authentication was rejected for an unknown ExApp or a wrong secret',
			self::DaemonRegistered => 'A deploy daemon that ExApps can be installed on was registered',
			self::DaemonUpdated => 'The configuration of a deploy daemon was changed',
			self::DaemonUnregistered => 'A deploy daemon was unregistered',
			self::DaemonRegistryAdded => 'A deploy daemon was set to pull the images of a Docker registry from another registry',
			self::DaemonRegistryRemoved => 'A Docker registry mapping was removed from a deploy daemon',
			self::AdminConfigUpdated => 'An AppAPI admin setting, such as the default deploy daemon, was changed',
		};
	}
}
