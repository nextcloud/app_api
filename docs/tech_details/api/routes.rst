.. _ex_app_routes:

======
Routes
======

Since AppAPI 3.0.0 ExApps have to declare their routes allowed to be accessed via the AppAPI ExApp proxy.

.. note::

	This routes check applied only for ExApp proxy (``/apps/app_api/proxy/*``).


Register
^^^^^^^^

During ExApp installation, the ExApp routes are registered automatically.
The routes must be declared in the ``external-app`` - ``routes`` tag of the ``info.xml`` file.

Example
*******

.. code-block::

    <routes>
		<route>
			<url>.*</url>
			<verb>GET,POST,PUT,DELETE</verb>
			<access_level>2</access_level>
			<headers_to_exclude>[]</headers_to_exclude>
		</route>
	</routes>

where the fields are:

- ``url``: the route to be registered on the ExApp side, can be a regex
- ``verb``: the HTTP verb that the route will accept, can be a comma separated list of verbs
- ``access_level``: the numeric access level required to access the route, 0 - public route, 1 - Nextcloud user auth required, 2 - admin user required
- ``headers_to_exclude``: a json encoded string of an array of strings, the headers that the ExApp wants to be excluded from the request to it


Unregister
^^^^^^^^^^

ExApp routes are unregistered automatically when the ExApp is uninstalling.
