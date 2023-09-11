=================
File Actions Menu
=================

FileActionsMenu is a simple API for registering entry to the file actions menu for ExApps.
AppAPI takes responsibility to register FileActionsMenu, ExApps needs only to register it in AppAPI.

.. note::

	FileActionsMenu rendered only for enabled ExApps.

Register
^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/files/actions/menu``

Params
******

Complete list of params (including optional):

.. code-block:: json

	{
		"name": "unique_name_of_file_actions_menu",
		"display_name": "Display name (for UI listing)",
		"mime": "mime of files where to display action menu",
		"permissions": "permissions",
		"order": "order_in_file_actions_menu",
		"icon": "url_to_icon",
		"icon_class": "icon-class (alternative way of setting icon)",
		"action_handler": "/action_handler_route (on ExApp)"
	}


Optional params
***************

	* `permissions` - File permissions required to display action menu, default: **31** (all permissions)
	* `order` - Order in file actions menu, default: **0**
	* `icon` - Url to icon, default: **null**
	* `icon_class` - Icon class instead of `icon`, default: **icon-app-ecosystem-v2**

Unregister
^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_api/api/v1/files/actions/menu``

Params
******

To unregister FileActionsMenu, you just need to provide name of registered FileActionsMenu:

.. code-block:: json

	{
		"fileActionMenuName": "unique_name_of_file_action_menu"
	}

Action payload to ExApp
^^^^^^^^^^^^^^^^^^^^^^^

When FileActionsMenu invoked, AppAPI forwards action for handling to ExApp.
The following data is sent to ExApp FileActionsMenu handler from the context of action:

.. code-block:: json

	{
		"actionName": "registered_files_actions_menu_name",
		"actionHandler": "/file_action_menu_ex_app_handler_route",
		"actionFile": {
			"fileId": "123",
			"name": "filename",
			"directory": "/relative/to/user/path/to/directory",
			"etag": "file_etag",
			"mime": "file_full_mime",
			"fileType": "dir/file",
			"mtime": "last modify time(integer)",
			"size": "integer",
			"favorite": "nc_favorite_flag",
			"permissions": "file_permissions_for_owner",
			"shareOwner": "optional, str",
			"shareOwnerId": "optional, str",
			"shareTypes": "optional, int",
			"shareAttributes": "optional, int",
			"sharePermissions": "optional, int",
			"userId": "string",
			"instanceId": "string",
		}
	}


Request flow
^^^^^^^^^^^^

General workflow of ExApp based on FileActionsMenu.

User action
***********

.. mermaid::

	sequenceDiagram
		User->>FileActionMenu: Press on registered ExApp action
		FileActionMenu->>AppAPI: send action context payload
		AppAPI->>ExApp: forward request to handler
		ExApp->>AppAPI: handler accepted action status
		AppAPI->>User: Alert (action sent or error)


Action results
**************

File processing results could be stored next to initial file or anywhere else,
e.g. on configured location in ExApp settings (``appconfig_ex``) or ExApp user settings (``preferences_ex``).

.. mermaid::

	sequenceDiagram
		ExApp->>Nextcloud: Upload result file
		ExApp->>AppAPI: Send notification about action results

Examples
^^^^^^^^

Here is a list of simple example ExApps based on FileActionsMenu:

* `video_to_gif <https://github.com/cloud-py-api/nc_py_api/tree/main/examples/as_app/to_gif>`_ - ExApp based on FileActionsMenu to convert videos to gif in place
* `upscaler_demo <https://github.com/cloud-py-api/upscaler_demo.git>`_ - ExApp based on FileActionsMenu to upscale image in place
