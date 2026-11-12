<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\AuditLog;

/**
 * Operations AppAPI writes to the admin audit log; log consumers match the values, so they never change
 */
enum Operation: string {
	// ExApps
	case ExAppInstallRequested = 'app_api.ex_app.install_requested';
	case ExAppRegistered = 'app_api.ex_app.registered';
	case ExAppUpdateRequested = 'app_api.ex_app.update_requested';
	case ExAppUpdated = 'app_api.ex_app.updated';
	case ExAppEnabled = 'app_api.ex_app.enabled';
	case ExAppDisabled = 'app_api.ex_app.disabled';
	case ExAppUninstallRequested = 'app_api.ex_app.uninstall_requested';
	case ExAppUnregistered = 'app_api.ex_app.unregistered';
	case ExAppDataDeleted = 'app_api.ex_app_data.deleted';
	case ExAppAuthFailed = 'app_api.ex_app_auth.failed';
	case HarpAuthFailed = 'app_api.harp_auth.failed';
	case TalkBotAuthFailed = 'app_api.talk_bot_auth.failed';

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
			self::ExAppInstallRequested => 'The installation of an ExApp was started in the web interface, with the names of its environment variables and its mounts',
			self::ExAppRegistered => 'An ExApp was registered and given its secret to authenticate to Nextcloud, before its deployment',
			self::ExAppUpdateRequested => 'The update of an ExApp was started in the web interface',
			self::ExAppUpdated => 'The version of an ExApp changed, at an update before the deployment of that version, or when the ExApp reported a newer one',
			self::ExAppEnabled => 'An ExApp was enabled and started',
			self::ExAppDisabled => 'An ExApp was disabled',
			self::ExAppUninstallRequested => 'The removal of the test deploy ExApp was started in the deploy daemon settings',
			self::ExAppUnregistered => 'An ExApp was removed from Nextcloud',
			self::ExAppDataDeleted => 'The deploy daemon confirmed that the data volume of an ExApp is removed',
			self::ExAppAuthFailed => 'A request with AppAPI authentication was rejected for an unknown ExApp or a wrong secret',
			self::HarpAuthFailed => 'A request made as HaRP was rejected for a missing or wrong shared key, logged with the user whose request HaRP forwarded',
			self::TalkBotAuthFailed => 'A message for the Talk bot of an ExApp was rejected for a wrong signature',
			self::DaemonRegistered => 'A deploy daemon that ExApps can be installed on was registered',
			self::DaemonUpdated => 'The configuration of a deploy daemon was changed',
			self::DaemonUnregistered => 'A deploy daemon was unregistered',
			self::DaemonRegistryAdded => 'A Docker registry mapping was added to a deploy daemon',
			self::DaemonRegistryRemoved => 'A Docker registry mapping was removed from a deploy daemon',
			self::AdminConfigUpdated => 'An AppAPI admin setting, such as the default deploy daemon, was changed or removed',
		};
	}
}
