======================
Miscellaneous OCS APIs
======================

There are some system utils APIs required for ExApps internal logic.

.. note::

	AppEcosystemAuth is required for all AppEcosystemV2 OCS APIs.


Get list of NC users
^^^^^^^^^^^^^^^^^^^^

OCS endpoint: ``GET /apps/app_ecosystem_v2/api/v1/users``

Response data
*************

Returns a list of user IDs only.

.. code-block:: json

	["user1", "user2", "user3"]

