<?php

declare(strict_types=1);

return [
	'routes' => [
		// AppAPI admin settings
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		// ExApps actions
		['name' => 'ExAppsPage#viewApps', 'url' => '/apps', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#listCategories', 'url' => '/apps/categories', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#listApps', 'url' => '/apps/list', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#enableApp', 'url' => '/apps/enable/{appId}', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#enableApp', 'url' => '/apps/enable/{appId}', 'verb' => 'POST' , 'root' => ''],
		['name' => 'ExAppsPage#getAppStatus', 'url' => '/apps/status/{appId}', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#enableApps', 'url' => '/apps/enable', 'verb' => 'POST' , 'root' => ''],
		['name' => 'ExAppsPage#disableApp', 'url' => '/apps/disable/{appId}', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#disableApps', 'url' => '/apps/disable', 'verb' => 'POST' , 'root' => ''],
		['name' => 'ExAppsPage#updateApp', 'url' => '/apps/update/{appId}', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#uninstallApp', 'url' => '/apps/uninstall/{appId}', 'verb' => 'GET' , 'root' => ''],
		['name' => 'ExAppsPage#viewApps', 'url' => '/apps/{category}', 'verb' => 'GET', 'defaults' => ['category' => ''] , 'root' => ''],
		['name' => 'ExAppsPage#viewApps', 'url' => '/apps/{category}/{id}', 'verb' => 'GET', 'defaults' => ['category' => '', 'id' => ''] , 'root' => ''],
		['name' => 'ExAppsPage#force', 'url' => '/apps/force', 'verb' => 'POST' , 'root' => ''],

		// DaemonConfig actions
		['name' => 'daemonConfig#getAllDaemonConfigs', 'url' => '/daemons', 'verb' => 'GET'],
		['name' => 'daemonConfig#registerDaemonConfig', 'url' => '/daemons', 'verb' => 'POST'],
		['name' => 'daemonConfig#unregisterDaemonConfig', 'url' => '/daemons/{name}', 'verb' => 'DELETE'],
		['name' => 'daemonConfig#verifyDaemonConnection', 'url' => '/daemons/{name}/check', 'verb' => 'POST'],
		['name' => 'daemonConfig#updateDaemonConfig', 'url' => '/daemons', 'verb' => 'PUT'],
	],
	'ocs' => [
		// Logging
		['name' => 'OCSApi#log', 'url' => '/api/v1/log', 'verb' => 'POST'],

		['name' => 'OCSApi#getNCUsersList', 'url' => '/api/v1/users', 'verb' => 'GET'],
		['name' => 'OCSApi#setAppProgress', 'url' => '/apps/status/{appId}', 'verb' => 'PUT'],

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

		// Talk bots
		['name' => 'talkBot#registerExAppTalkBot', 'url' => '/api/v1/talk_bot', 'verb' => 'POST'],
		['name' => 'talkBot#unregisterExAppTalkBot', 'url' => '/api/v1/talk_bot', 'verb' => 'DELETE'],

		// Speech-To-Text
		['name' => 'speechToText#registerProvider', 'url' => '/api/v1/speech_to_text', 'verb' => 'POST'],
		['name' => 'speechToText#unregisterProvider', 'url' => '/api/v1/speech_to_text', 'verb' => 'DELETE'],

		// Text-Processing
		['name' => 'textProcessing#registerProvider', 'url' => '/api/v1/text_processing', 'verb' => 'POST'],
		['name' => 'textProcessing#unregisterProvider', 'url' => '/api/v1/text_processing', 'verb' => 'DELETE'],
		['name' => 'textProcessing#registerProvider', 'url' => '/api/v1/text_processing/task_type', 'verb' => 'POST'],
	],
];
