===========
Development
===========

This section will contain necessary information regarding development process of the project.

Pre-condition
^^^^^^^^^^^^^

To make development commands work, we assume that your development environment is setup using `nextcloud-docker-dev <https://github.com/juliushaertl/nextcloud-docker-dev>`_.

Make commands
^^^^^^^^^^^^^

There are several make commands available to ease frequent development actions.
You can see all of them by running ``make help``.


Docker remote API
*****************

The Docker Engine remote API can be easily configured via ``make dock2port`` and ``make dock-certs`` commands.
The first one will create a docker container to provide remote Docker Engine API.
The second one will configure generated certificates for created container with Docker remote API in Nextcloud.

After that register DaemonConfigs in Nextcloud using ``make dock-port`` command.

Docker by socket
****************

If you want to use Docker by socket, use ``make dock2sock`` command.
It will register DaemonConfigs in Nextcloud for default socket connection (``/var/run/docker.sock``).
Make sure that this socket has enough permissions for Nextcloud and webserver user to access it
and actually forwarded to the container:

.. code-block::

	...
	volumes:
		...
		- /var/run/docker.sock:/var/run/docker.sock
		...


Dev changes to Nextcloud server
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The only changes to Nextcloud server are in ``base.php`` file.
Until these changes not implemented in server, they can be applied by patch (``base_php.patch`` in project root directory).

.. code-block:: php

	if (self::tryAppEcosystemV2Login($request)) {
		return true;
	}


And down below ``tryAppEcosystemV2Login`` method is added:

.. code-block:: php

	protected static function tryAppEcosystemV2Login(OCP\IRequest $request): bool {
		$appManager = Server::get(OCP\App\IAppManager::class);
		if (!$request->getHeader('AE-SIGNATURE')) {
			return false;
		}
		if (!$appManager->isInstalled('app_ecosystem_v2')) {
			return false;
		}
		$appEcosystemV2Service = Server::get(OCA\AppEcosystemV2\Service\AppEcosystemV2Service::class);
		return $appEcosystemV2Service->validateExAppRequestToNC($request);
	}

