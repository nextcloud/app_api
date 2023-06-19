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

		// ExAppUsers
		['name' => 'ocsApi#getExAppUsers', 'url' => 'api/v1/users', 'verb' => 'GET'],

		// Ex Apps registration
		['name' => 'ocsApi#registerExternalApp', 'url' => '/api/v1/ex-app', 'verb' => 'POST'],
		['name' => 'ocsApi#unregisterExternalApp', 'url' => '/api/v1/ex-app', 'verb' => 'DELETE'],
		['name' => 'ocsApi#getAppStatus', 'url' => '/api/v1/ex-app/{appId}/status', 'verb' => 'GET'],

		// File Actions Menu
		['name' => 'ocsApi#registerFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'POST'],
		['name' => 'ocsApi#unregisterFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'DELETE'],
		['name' => 'ocsApi#handleFileAction', 'url' => '/api/v1/files/action', 'verb' => 'POST'],
		['name' => 'ocsApi#loadFileActionIcon', 'url' => '/api/v1/files/action/icon', 'verb' => 'GET'],

		// appconfig_ex (app configuration)
		['name' => 'appConfig#setAppConfigValue', 'url' => '/api/v1/ex-app/config', 'verb' => 'POST'],
		['name' => 'appConfig#getAppConfigValues', 'url' => '/api/v1/ex-app/config', 'verb' => 'GET'],
		['name' => 'appConfig#deleteAppConfigValues', 'url' => '/api/v1/ex-app/config', 'verb' => 'DELETE'],

		// preferences_ex (user-specific configuration)
		['name' => 'preferences#setUserConfigValue', 'url' => '/api/v1/ex-app/preference', 'verb' => 'POST'],
		['name' => 'preferences#getUserConfigValues', 'url' => '/api/v1/ex-app/preference/get-values', 'verb' => 'POST'],
		['name' => 'preferences#deleteUserConfigValues', 'url' => '/api/v1/ex-app/preference', 'verb' => 'DELETE'],

//	TODO: Implement Notifications, SearchProvider, BackgroundJob, SettingsPage, SettingsSection, EventListener, DashboardWidget, Capabilities

		// Notifications
//		['name' => 'notification#sendNotification', 'url' => '/api/v1/notification', 'verb' => 'POST'],
//		['name' => 'notification#registerNotificationProvider', 'url' => '/api/v1/notification-provider', 'verb' => 'POST'],
//		['name' => 'notification#unregisterNotificationProvider', 'url' => '/api/v1/notification-provider', 'verb' => 'DELETE'],

		// Unified search
//		['name' => 'search#registerSearchProvider', 'url' => '/api/v1/search-provider', 'verb' => 'POST'],
//		['name' => 'search#unregisterSearchProvider', 'url' => '/api/v1/search-provider', 'verb' => 'DELETE'],

		// Background jobs
//		['name' => 'backgroundJobs#registerBackgroundJob', 'url' => '/api/v1/background-job', 'verb' => 'POST'],
//		['name' => 'backgroundJobs#unregisterBackgroundJob', 'url' => '/api/v1/background-job', 'verb' => 'DELETE'],

		// Settings API (admin/user settings registration)
//		['name' => 'settings#registerSettingsPage', 'url' => '/api/v1/settings/page', 'verb' => 'POST'],
//		['name' => 'settings#unregisterSettingsPage', 'url' => '/api/v1/settings/page', 'verb' => 'DELETE'],
//		['name' => 'settings#registerSettingsSection', 'url' => '/api/v1/settings/section', 'verb' => 'POST'],
//		['name' => 'settings#unregisterSettingsSection', 'url' => '/api/v1/settings/section', 'verb' => 'DELETE'],

		// Event listeners
//		['name' => 'events#registerEventListener', 'url' => '/api/v1/event-listener', 'verb' => 'POST'],
//		['name' => 'events#registerEventListener', 'url' => '/api/v1/event-listener', 'verb' => 'DELETE'],

		// Dashboard widgets
//		['name' => 'dashboard#registerDashboardWidget', 'url' => '/api/v1/dashboard', 'verb' => 'POST'],
//		['name' => 'dashboard#unregisterDashboardWidget', 'url' => '/api/v1/dashboard', 'verb' => 'DELETE'],
//	    ['name' => 'dashboard#loadDashboardWidgetData', 'url' => '/api/v1/dashboard/data', 'verb' => 'GET'], // For dashboard items list
	],
];
