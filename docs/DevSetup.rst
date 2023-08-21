.. _dev-setup:

Setting up dev environment
==========================

We highly recommend using `Julius Haertl docker setup <https://github.com/juliushaertl/nextcloud-docker-dev>`_ for the Nextcloud dev setup.

Suggested IDE: **PhpStorm**, though you can certainly use any IDE of your preference such as **VS Code** or **Vim**.

Get last version from GitHub
""""""""""""""""""""""""""""

Assuming you're in the ``apps`` folder of Nextcloud with command :command:`git`::

	git clone https://github.com/cloud-py-api/app_ecosystem_v2.git

Move to the ``app_ecosystem_v2`` directory with :command:`shell`::

	cd app_ecosystem_v2

Then, build NPM and JS with :command:`shell`::

	npm ci && npm run build

After this, you can enable it from the directory where the ``occ`` command resides, with :command:`shell`::

	./occ app:enable --force app_ecosystem_v2


Patching Nextcloud 26
"""""""""""""""""""""

Although only Nextcloud since version 27.1 is officially supported, installation on Nextcloud version 26 is possible.
If you are not using NextCloud version 26, you can skip this section.

The only changes to Nextcloud server are in ``base.php`` file, required only for **Nextcloud 26**.

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

.. note:: The patch itself can be found in the project root directory under the name ``base_php.patch``.

Apply the patch from the root directory of Nextcloud using :command:`patch`::

	patch -p 1 -i apps/app_ecosystem_v2/base_php.patch


In Place of a Conclusion
""""""""""""""""""""""""

There are several make commands available to ease frequent development actions.

To see the complete list, execute ``make help``.

Docker remote API
*****************

The Docker Engine remote API can be easily configured via ``make dock2port`` and ``make dock-certs`` commands.
The first one will create a docker container to provide remote Docker Engine API.
The second one will configure generated certificates for created container with Docker remote API in Nextcloud.

Afterward, register DaemonConfigs in Nextcloud using ``make dock-port`` command.

Docker by socket
****************

For Docker via socket, use the command ``make dock2sock``.
This registers DaemonConfigs in Nextcloud for the default socket connection (``/var/run/docker.sock``).

Make sure that socket has enough permissions for Nextcloud and webserver user to access it
and actually forwarded to the container:

.. code-block::

	...
	volumes:
		...
		- /var/run/docker.sock:/var/run/docker.sock
		...
