===========
Preferences
===========

ExApp preferences API is similar to the standard preferences API.
It's a user specific settings.


Set user config value
^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/ex-app/preference``

Request data
************

Set config value for **current authenticated user**.

.. code-block:: json

	{
		"configKey": "key",
		"configValue": "value"
	}

Response data
*************

On success ExAppPreference object is returned.
If config value is not set - ``null`` is returned.


Get user config values
^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/ex-app/preference/get-values``

Request data
************

Get config values for **current authenticated user**.

.. code-block:: json

	{
		"configKeys": ["key1", "key2", "key3"]
	}

Delete user config values
^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_api/api/v1/ex-app/preference``

Request data
************

Delete config values for **current authenticated user**.

.. code-block:: json

	{
		"configKeys": ["key1", "key2", "key3"]
	}

Response
********

Returns the number of configuration values removed.
