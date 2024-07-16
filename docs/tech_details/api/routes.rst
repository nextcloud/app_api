.. _ex_app_routes:

======
Routes
======

This OCS API is mandatory for all ExApps to register their routes during the ExApp enable/disable step.

.. note::

	Available and mandatory since AppAPI 3.0.0, requires AppAPIAuth.


Register
^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/routes``

Params
******

.. code-block:: json

    {
        "routes": [
            {
                "url": "/regex-route-on-ex-app-side",
                "verb": "GET, POST, PUT, DELETE",
                "access_level": "0/1/2",
                "headers_to_include": "json_encoded string of array of strings ['headerName1', 'headerName2', ...]",
            }
        ]
    }

where the fields are:

- ``url``: the route to be registered on the ExApp side, can be a regex
- ``verb``: the HTTP verb that the route will accept
- ``access_level``: the numeric access level required to access the route, 0 - public route, 1 - Nextcloud user auth required, 2 - admin user required
- ``headers_to_include``: a json encoded string of an array of strings, the headers that the ExApp wants to be included in the request to it


Unregister
^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_api/api/v1/routes``
