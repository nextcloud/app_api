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
	],
	'ocs' => [
		// Logging
		['name' => 'ocsApi#log', 'url' => '/api/v1/log', 'verb' => 'POST'],

		// Ex Apps registration
		['name' => 'ocsApi#registerExternalApp', 'url' => '/api/v1/register-ex-app', 'verb' => 'POST'],
		['name' => 'ocsApi#unregisterExternalApp', 'url' => '/api/v1/unregister-app', 'verb' => 'DELETE'],
		['name' => 'ocsApi#getAppStatus', 'url' => '/api/v1/ex-app/{appId}/status', 'verb' => 'GET'],

		// File Actions Menu
		['name' => 'ocsApi#registerFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'POST'],
		['name' => 'ocsApi#unregisterFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'DELETE'],
		['name' => 'ocsApi#handleFileAction', 'url' => '/api/v1/files/action', 'verb' => 'POST'],

		// Notifications
		['name' => 'ocsApi#sendNotification', 'url' => '/api/v1/send-notification', 'verb' => 'POST'],

		// Unified search
		['name' => 'ocsApi#registerSearchProvider', 'url' => '/api/v1/register-search-provider', 'verb' => 'POST'],

		// Background jobs
		['name' => 'ocsApi#registerBackgroundJob', 'url' => '/api/v1/register-background-job', 'verb' => 'POST'],

		// Storage API (preferences)
		['name' => 'ocsApi#setAppConfigValue', 'url' => '/api/v1/ex-app/config', 'verb' => 'POST'],
		['name' => 'ocsApi#getAppConfigValue', 'url' => '/api/v1/ex-app/config', 'verb' => 'GET'],
		['name' => 'ocsApi#getAppConfigKeys', 'url' => '/api/v1/ex-app/config/keys', 'verb' => 'GET'],
		['name' => 'ocsApi#deleteAppConfigValue', 'url' => '/api/v1/ex-app/config', 'verb' => 'DELETE'],
		['name' => 'ocsApi#deleteAppConfigValues', 'url' => '/api/v1/ex-app/config/all', 'verb' => 'DELETE'],
		['name' => 'ocsApi#setUserConfigValue', 'url' => '/api/v1/ex-app/config/user', 'verb' => 'POST'],
		['name' => 'ocsApi#getUserConfigValue', 'url' => '/api/v1/ex-app/config/user', 'verb' => 'GET'],
		['name' => 'ocsApi#getUserConfigKeys', 'url' => '/api/v1/ex-app/config/user/keys', 'verb' => 'GET'],
		['name' => 'ocsApi#deleteUserConfigValue', 'url' => '/api/v1/ex-app/config/user', 'verb' => 'DELETE'],
		['name' => 'ocsApi#deleteUserConfigValues', 'url' => '/api/v1/ex-app/config/user/all', 'verb' => 'DELETE'],

		// Settings API (admin/user settings registration)
		['name' => 'ocsApi#registerSettingsPage', 'url' => '/api/v1/register-settings-page', 'verb' => 'POST'],
		['name' => 'ocsApi#registerSettingsSection', 'url' => '/api/v1/register-settings-section', 'verb' => 'POST'],

		// Event listeners
		['name' => 'ocsApi#registerEventListener', 'url' => '/api/v1/register-event-listener', 'verb' => 'POST'],

		// Dashboard widgets
		['name' => 'ocsApi#registerDashboardWidget', 'url' => '/api/v1/register-dashboard-widget', 'verb' => 'POST'],
	],
];
