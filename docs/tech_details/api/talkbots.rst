=========
Talk bots
=========

AppEcosystemV2 provides API for registering ExApps Talk bots.
This means that ExApps could be just as Talk bot or it could be as one of the options of the app.
Read more about Talk bots `here <https://nextcloud-talk.readthedocs.io/en/latest/bots/>`_.

Register ExApp Talk bot (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``POST /apps/app_ecosystem_v2/api/v1/talk_bot``

Request data
************

.. code-block:: json

	{
		"name": "Talk bot display name",
		"route": "/talk_bot_webhook_route_on_ex_app",
		"description": "Talk bot description",
	}


Unregister ExApp Talk bot (OCS)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

To unregister ExApp Talk bot you will have to pass route on which registered Talk bot.

OCS endpoint: ``DELETE /apps/app_ecosystem_v2/api/v1/talk_bot``

Request data
************

.. code-block:: json

	{
		"route": "/route_of_talk_bot"
	}

