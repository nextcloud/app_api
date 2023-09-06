.. _create-deploy-daemon:

Creation of Deploy Daemon
=========================

The Deploy Daemon (DaemonConfig) is used to orchestrate the deployment of ExApps.

.. note::

	Currently only Docker (``accepts-deploy-id: docker-install``) is supported as a Deploy Daemon.

OCC CLI
^^^^^^^

There are a few commands to manage Deploy Daemons:

1. Register ``occ app_api:daemon:register``
2. Unregister ``occ app_api:daemon:unregister``
3. List registered daemons ``occ app_api:daemon:list``

Register
--------

Register Deploy Daemon (DaemonConfig).

Command: ``app_api:daemon:register [--net NET] [--hostname HOSTNAME] [--ssl_key SSL_KEY] [--ssl_key_password SSL_KEY_PASSWORD] [--ssl_cert SSL_CERT] [--ssl_cert_password SSL_CERT_PASSWORD] [--gpu GPU] [--] <name> <display-name> <accepts-deploy-id> <protocol> <host> <nextcloud_url>``

Arguments
*********

	* ``name`` - unique name of the daemon (e.g. ``docker_local_sock``)
	* ``display-name`` - name of the daemon (e.g. ``My Local Docker``, will be displayed in the UI)
	* ``accepts-deploy-id`` - type of deployment (``docker-install`` or ``manual-install``)
	* ``protocol`` - protocol used to connect to the daemon (``unix-socket``, ``http`` or ``https``)
	* ``host`` - host of the daemon (e.g. ``/var/run/docker.sock`` for ``unix-socket`` protocol or ``host:port`` for ``http(s)`` protocol)
	* ``nextcloud_url`` - Nextcloud URL, Daemon config required option (e.g. ``https://nextcloud.local``)

Options
*******

	* ``--net [network-name]``  - ``[required]`` network name to bind docker container to (default: ``host``)
	* ``--hostname HOST`` - ``[required]`` host to expose daemon to (defaults to ExApp appid)
	* ``--ssl_key SSL_KEY`` - ``[optional]`` path to SSL key file (local absolute path)
	* ``--ssl_password SSL_PASSWORD`` - ``[optional]`` SSL key password
	* ``--ssl_cert SSL_CERT`` - ``[optional]`` path to SSL cert file (local absolute path)
	* ``--ssl_cert_password SSL_CERT_PASSWORD`` - ``[optional]`` SSL cert password
	* ``--gpu GPU`` - ``[optional]`` GPU device to expose to the daemon (e.g. ``/dev/dri``)

DeployConfig
************

DeployConfig is a set of additional options in Daemon config, which are used in deployment algorithms to configure
ExApp container.

.. code-block:: json

	{
		"net": "nextcloud",
		"host": null,
		"nextcloud_url": "https://nextcloud.local",
		"ssl_key": "/path/to/ssl/key.pem",
		"ssl_key_password": "ssl_key_password",
		"ssl_cert": "/path/to/ssl/cert.pem",
		"ssl_cert_password": "ssl_cert_password",
		"gpus": ["/dev/dri"],
	}

DeployConfig options
""""""""""""""""""""

	* ``net`` **[required]** - network name to bind docker container to (default: ``host``)
	* ``host`` *[optional]* - in case Docker is on remote host, this should be a hostname of remote machine
	* ``nextcloud_url`` **[required]** - Nextcloud URL (e.g. ``https://nextcloud.local``)
	* ``ssl_key`` *[optional]* - path to SSL key file (local absolute path)
	* ``ssl_key_password`` *[optional]* - SSL key password
	* ``ssl_cert`` *[optional]* - path to SSL cert file (local absolute path)
	* ``ssl_cert_password`` *[optional]* - SSL cert password
	* ``gpus`` *[optional]* - GPU device to attach to the daemon (e.g. ``/dev/dri``)

Unregister
----------

Unregister Deploy Daemon (DaemonConfig).

Command: ``app_api:daemon:unregister <daemon-config-name>``

List registered daemons
-----------------------

List registered Deploy Daemons (DaemonConfigs).

Command: ``app_api:daemon:list``

Nextcloud AIO
^^^^^^^^^^^^^

In case of AppAPI installed in AIO, default Deploy Daemon is registered automatically.
It is possible to register additional Deploy Daemons with the same ways as described above.
