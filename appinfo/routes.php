<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

return [
	'routes' => [
		// AppEcosystemV2 admin settings
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		// Ex Apps registration
		['name' => 'api#registerExternalApp', 'url' => '/api/v1/register-ex-app', 'verb' => 'POST'],
		['name' => 'api#unregisterExternalApp', 'url' => '/api/v1/unregister-app', 'verb' => 'DELETE'],
		['name' => 'api#getAppStatus', 'url' => '/api/v1/ex-app/{appId}/status', 'GET'],

		// File Actions Menu
		['name' => 'api#registerFileActionMenu', 'url' => '/api/v1/register-file-action-menu', 'POST'],

		// Notifications
		['name' => 'api#sendNotification', 'url' => '/api/v1/send-notification', 'POST'],

		// Unified search
		['name' => 'api#registerSearchProvider', 'url' => '/api/v1/register-search-provider', 'POST'],

		// Background jobs
		['name' => 'api#registerBackgroundJob', 'url' => '/api/v1/register-background-job', 'POST'],

		// Storage API (preferences)
		['name' => 'api#setAppConfigValue', 'url' => '/api/v1/set-app-config-value', 'POST'],
		['name' => 'api#getAppConfigValue', 'url' => '/api/v1/get-app-config-value', 'GET'],
		['name' => 'api#setUserConfigValue', 'url' => '/api/v1/set-user-config-value', 'POST'],
		['name' => 'api#getUserConfigValue', 'url' => '/api/v1/get-user-config-value', 'GET'],

		// Settings API (admin/user settings registration)
		['name' => 'api#registerSettingsPage', 'url' => '/api/v1/register-settings-page', 'POST'],
		['name' => 'api#registerSettingsSection', 'url' => '/api/v1/register-settings-section', 'POST'],

		// Event listeners
		['name' => 'api#registerEventListener', 'url' => '/api/v1/register-event-listener', 'POST'],

		// Dashboard widgets
		['name' => 'api#registerDashboardWidget', 'url' => '/api/v1/register-dashboard-widget', 'POST'],
	],
	'ocs' => [
		// Logging
		['name' => 'ocsApi#log', 'url' => '/api/v1/log', 'POST'],
	],
];
