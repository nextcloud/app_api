=========
AppConfig
=========

ExApp AppConfig API is similar to the standard Nextcloud **appconfig** API.

Set app config value
^^^^^^^^^^^^^^^^^^^^

Set ExApp config value.

OCS endpoint: ``POST /apps/app_api/api/v1/ex-app/config``

Request data
************

.. code-block:: json

	{
		"key": "key",
		"value": "value"
		"sensitive": "sensitive flag affecting the visibility of the value (true/false, default: false)"
	}

Response data
*************

On success, ExAppConfig object is returned.

.. code-block:: json

	{
		"key": "key",
		"value": "value"
		"sensitive": "true/false"
	}

Update app config value or sensitive flag
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Update existing ExApp config value or sensitive flag.
It's the same endpoint as for setting config value.

OCS endpoint: ``POST /apps/app_api/api/v1/ex-app/config``

.. code-block:: json

	{
		"key": "key",
		"value": "value"
		"sensitive": "(optional), will not be updated if not provided"
	}

Get app config values
^^^^^^^^^^^^^^^^^^^^^

Get ExApp config values

OCS endpoint: ``POST /apps/app_api/api/v1/ex-app/config/get-values``

Request data
************

.. code-block:: json

	{
		"configKeys": ["key1", "key2", "key3"]
	}

Response data
*************

List of ExApp config values are returned.

.. code-block:: json

	[
		{ "configkey": "key1", "configvalue": "value1" },
		{ "configkey": "key2", "configvalue": "value2" },
		{ "configkey": "key3", "configvalue": "value3" },
	]

Delete app config values
^^^^^^^^^^^^^^^^^^^^^^^^

Delete ExApp config values.

OCS endpoint: ``DELETE /apps/app_api/api/v1/ex-app/config``

Request data
************

.. code-block:: json

	{
		"configKeys": ["key1", "key2", "key3"]
	}

Response
********

Returns the number of configuration values removed.
