===============
Text-Processing
===============

AppEcosystemV2 provides a text-processing service
that can be used to register ExApps providers and to process passed through text.

Registering text-processing provider (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_ecosystem_v2/api/v1/text_processing``

Request data
************

.. code-block:: json

	{
		"name": "unique_provider_name",
		"display_name": "Provider Display Name",
		"description": "Provider Description",
		"action_handler_route": "/handler_route_on_ex_app",
		"action_type": "unique_task_type_name",
	}

Response
********

On successful registration response with status code 200 is returned.

Unregistering text-processing provider (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_ecosystem_v2/api/v1/text_processing``

Request data
************

.. code-block:: json

	{
		"name": "unique_provider_name",
	}

Response
********

On successful unregister response with status code 200 is returned.


Registering Text-Processing task type (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_ecosystem_v2/api/v1/text_processing/task_type``

Request data
************

.. code-block:: json

	{
		"name": "unique_task_type_name",
		"display_name": "Task Type Display Name",
		"description": "Task Type Description",
	}

Response
********

On successful registration response with status code 200 is returned.

Unregistering Text-Processing task type (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_ecosystem_v2/api/v1/text_processing/task_type``

Request data
************

.. code-block:: json

	{
		"name": "unique_task_type_name",
	}

Response
********

On successful unregister response with status code 200 is returned.

