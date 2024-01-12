===================
Machine Translation
===================

AppAPI provides a Machine-Translation providers registration mechanism for ExApps.

.. note::

	Available since Nextcloud 29.

Registering translation provider (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/ai_provider/translation``

Request data
************

.. code-block:: json

	{
		"name": "unique_provider_name",
		"display_name": "Provider Display Name",
		"action_handler": "/handler_route_on_ex_app",
		"from_languages": "en,de,fr",
		"from_languages_labels": "English,German,French",
		"to_languages": "fr,en,de",
		"to_languages_labels": "French,English,German,",
	}

.. note::

	``from_languages`` and ``to_languages`` are comma separated language codes.
	``from_languages_labels`` and ``to_languages_labels`` are comma separated language labels.


Response
********

On successful registration response with status code 200 is returned.

Unregistering translation provider (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_api/api/v1/ai_provider/translation``

Request data
************

.. code-block:: json

	{
		"name": "unique_provider_name",
	}

Response
********

On successful unregister response with status code 200 is returned.


Report translation result (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``PUT /apps/app_api/api/v1/ai_provider/translation``

Request data
************

.. code-block:: json

	{
		"task_id": "queued_task_id",
		"result": "translated_text",
		"error": "error_message_if_any",
	}
