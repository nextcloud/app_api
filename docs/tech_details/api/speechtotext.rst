==============
Speech-To-Text
==============

AppAPI provides a Speech-To-Text (STT) service
that can be used to register ExApp as a custom STT model and transcribe audio files via it.

Registering ExApp STT provider (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/speech_to_text``

Request data
************

.. code-block:: json

	{
		"name": "unique_provider_name",
		"display_name": "Provider Display Name",
		"action_handler_route": "/handler_route_on_ex_app",
	}


Response
********

On successful registration response with status code 200 is returned.

Unregistering ExApp STT provider (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_api/api/v1/speech_to_text``

Request data
************

.. code-block:: json

	{
		"name": "unique_provider_name",
	}


Response
********

On successful unregister response with status code 200 is returned.
