<?php

declare(strict_types=1);

return [
	'routes' => [
		// AppEcosystemV2 admin settings
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		// ExApps actions
		['name' => 'exApp#registerExApp', 'url' => '/api/v1/ex-app', 'verb' => 'POST'],
		['name' => 'exApp#unregisterExApp', 'url' => '/api/v1/ex-app', 'verb' => 'DELETE'],
		['name' => 'exApp#updateExApp', 'url' => '/api/v1/ex-app/{appId}/update', 'verb' => 'POST'],
	],
	'ocs' => [
		// Logging
		['name' => 'OCSApi#log', 'url' => '/api/v1/log', 'verb' => 'POST'],

		['name' => 'OCSApi#getNCUsersList', 'url' => '/api/v1/users', 'verb' => 'GET'],

		// ExApps
		['name' => 'OCSExApp#getExAppsList', 'url' => '/api/v1/ex-app/{list}', 'verb' => 'GET'],

		// ExApps actions
		['name' => 'OCSExApp#setExAppEnabled', 'url' => '/api/v1/ex-app/{appId}/enabled', 'verb' => 'PUT'],

		// File Actions Menu
		['name' => 'ExFileActionsMenu#registerFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'POST'],
		['name' => 'ExFileActionsMenu#unregisterFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'DELETE'],
		['name' => 'ExFileActionsMenu#handleFileAction', 'url' => '/api/v1/files/action', 'verb' => 'POST'],
		['name' => 'ExFileActionsMenu#loadFileActionIcon', 'url' => '/api/v1/files/action/icon', 'verb' => 'GET'],

		// appconfig_ex (app configuration)
		['name' => 'appConfig#setAppConfigValue', 'url' => '/api/v1/ex-app/config', 'verb' => 'POST'],
		['name' => 'appConfig#getAppConfigValues', 'url' => '/api/v1/ex-app/config/get-values', 'verb' => 'POST'],
		['name' => 'appConfig#deleteAppConfigValues', 'url' => '/api/v1/ex-app/config', 'verb' => 'DELETE'],

		// preferences_ex (user-specific configuration)
		['name' => 'preferences#setUserConfigValue', 'url' => '/api/v1/ex-app/preference', 'verb' => 'POST'],
		['name' => 'preferences#getUserConfigValues', 'url' => '/api/v1/ex-app/preference/get-values', 'verb' => 'POST'],
		['name' => 'preferences#deleteUserConfigValues', 'url' => '/api/v1/ex-app/preference', 'verb' => 'DELETE'],

		// Notifications
		['name' => 'notifications#sendNotification', 'url' => '/api/v1/notification', 'verb' => 'POST'],
	],
];
