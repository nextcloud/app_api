<?php

declare(strict_types=1);

return [
	'routes' => [
		// AppAPI admin settings
		['name' => 'Config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		// Menu Entries
		['name' => 'TopMenu#ExAppIcon', 'url' => '/embedded/{appId}/{name}/icon', 'verb' => 'GET' , 'root' => '/embedded'],
		['name' => 'TopMenu#viewExAppPage',
			'url' => '/embedded/{appId}/{name}/{other}', 'verb' => 'GET' , 'root' => '/embedded',
			'requirements' => array('other' => '.*'), 'defaults' => array('other' => '')],

		// Proxy
		['name' => 'ExAppProxy#ExAppGet',
			'url' => '/proxy/{appId}/{other}', 'verb' => 'GET' , 'root' => '/proxy',
			'requirements' => array('other' => '.+'), 'defaults' => array('other' => '')],
		['name' => 'ExAppProxy#ExAppPost',
			'url' => '/proxy/{appId}/{other}', 'verb' => 'POST' , 'root' => '/proxy',
			'requirements' => array('other' => '.+'), 'defaults' => array('other' => '')],
		['name' => 'ExAppProxy#ExAppPut',
			'url' => '/proxy/{appId}/{other}', 'verb' => 'PUT' , 'root' => '/proxy',
			'requirements' => array('other' => '.+'), 'defaults' => array('other' => '')],

		// ExApps actions
		['name' => 'ExAppsPage#viewApps', 'url' => '/apps', 'verb' => 'GET' , 'root' => '/apps'],
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
		['name' => 'DaemonConfig#getAllDaemonConfigs', 'url' => '/daemons', 'verb' => 'GET'],
		['name' => 'DaemonConfig#registerDaemonConfig', 'url' => '/daemons', 'verb' => 'POST'],
		['name' => 'DaemonConfig#unregisterDaemonConfig', 'url' => '/daemons/{name}', 'verb' => 'DELETE'],
		['name' => 'DaemonConfig#verifyDaemonConnection', 'url' => '/daemons/{name}/check', 'verb' => 'POST'],
		['name' => 'DaemonConfig#updateDaemonConfig', 'url' => '/daemons', 'verb' => 'PUT'],
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

		// appconfig_ex (app configuration)
		['name' => 'AppConfig#setAppConfigValue', 'url' => '/api/v1/ex-app/config', 'verb' => 'POST'],
		['name' => 'AppConfig#getAppConfigValues', 'url' => '/api/v1/ex-app/config/get-values', 'verb' => 'POST'],
		['name' => 'AppConfig#deleteAppConfigValues', 'url' => '/api/v1/ex-app/config', 'verb' => 'DELETE'],

		// preferences_ex (user-specific configuration)
		['name' => 'Preferences#setUserConfigValue', 'url' => '/api/v1/ex-app/preference', 'verb' => 'POST'],
		['name' => 'Preferences#getUserConfigValues', 'url' => '/api/v1/ex-app/preference/get-values', 'verb' => 'POST'],
		['name' => 'Preferences#deleteUserConfigValues', 'url' => '/api/v1/ex-app/preference', 'verb' => 'DELETE'],

		// Notifications
		['name' => 'Notifications#sendNotification', 'url' => '/api/v1/notification', 'verb' => 'POST'],

		// Talk bots
		['name' => 'TalkBot#registerExAppTalkBot', 'url' => '/api/v1/talk_bot', 'verb' => 'POST'],
		['name' => 'TalkBot#unregisterExAppTalkBot', 'url' => '/api/v1/talk_bot', 'verb' => 'DELETE'],

		// File Actions Menu
		['name' => 'OCSUi#registerFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'POST'],
		['name' => 'OCSUi#unregisterFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'DELETE'],
		['name' => 'OCSUi#getFileActionMenu', 'url' => '/api/v1/files/actions/menu', 'verb' => 'GET'],
		['name' => 'OCSUi#handleFileAction', 'url' => '/api/v1/files/action', 'verb' => 'POST'],
		['name' => 'OCSUi#loadFileActionIcon', 'url' => '/api/v1/files/action/icon', 'verb' => 'GET'],

		// Top Menu
		['name' => 'OCSUi#registerExAppMenuEntry', 'url' => '/api/v1/ui/top-menu', 'verb' => 'POST'],
		['name' => 'OCSUi#unregisterExAppMenuEntry', 'url' => '/api/v1/ui/top-menu', 'verb' => 'DELETE'],
		['name' => 'OCSUi#getExAppMenuEntry', 'url' => '/api/v1/ui/top-menu', 'verb' => 'GET'],
	],
];
