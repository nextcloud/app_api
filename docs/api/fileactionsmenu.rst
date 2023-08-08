=================
File Actions Menu
=================

FileActionsMenu is a simple API for registering entry to the file actions menu for ExApps.
AppEcosystemV2 takes responsibility to register FileActionsMenu, ExApps needs only to register it in AppEcosystemV2.

.. note::

	FileActionsMenu rendered only for enabled ExApps.

Register
^^^^^^^^

OCS endpoint: ``POST /apps/app_ecosystem_v2/api/v1/files/actions/menu``

Params
******

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


Unregister
^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_ecosystem_v2/api/v1/files/actions/menu``

Params
******


.. code-block:: json

	{
		"fileActionMenuName": "unique_name_of_file_action_menu"
	}

Action payload to ExApp
^^^^^^^^^^^^^^^^^^^^^^^

When FileActionsMenu invoked, AppEcosystemV2 forwards action for handling to ExApp.
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
			"favorite": "nc_favorite_flag",
			"permissions": "file_permissions_for_owner",
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
		FileActionMenu->>AppEcosystemV2: send action context payload
		AppEcosystemV2->>ExApp: forward request to handler
		ExApp->>AppEcosystemV2: handler accepted action status
		AppEcosystemV2->>User: Alert (action sent or error)


Action results
**************

File processing results could be stored next to initial file or anywhere else,
e.g. on configured location in ExApp settings (``appconfig_ex``) or ExApp user settings (``preferences_ex``).

.. mermaid::

	sequenceDiagram
		ExApp->>Nextcloud: Upload result file
		ExApp->>AppEcosystemV2: Send notification about action results

Examples
^^^^^^^^

Here is a list of simple example ExApps based on FileActionsMenu:

* `video_to_gif <https://github.com/cloud-py-api/nc_py_api/tree/main/examples/as_app/to_gif>`_ - ExApp based on FileActionsMenu to convert videos to gif in place
* `upscaler_demo <https://github.com/cloud-py-api/upscaler_demo.git>`_ - ExApp based on FileActionsMenu to upscale image in place
