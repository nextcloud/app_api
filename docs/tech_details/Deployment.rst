Deployment
==========

Overview
--------

AppAPI ExApps deployment process in short consists of 3 steps:

1. `DaemonConfig registration`_
2. `ExApp deployment`_
3. `ExApp registration`_


DaemonConfig registration
-------------------------

The first step is to register DaemonConfig, where your ExApps will be deployed.
Before that you will need to configure your Docker socket to be accessible by Nextcloud instance and webserver user.
In case of remote Docker Engine API, you will need to expose it so it is accessible by Nextcloud instance and import certificates.

.. note::
	For now only Docker daemon ``accepts-deploy-id: docker-install`` is supported.
	For development and manually deployed app in docker there is ``accepts-deploy-id: manual-install``.

This can be done by ``occ`` CLI command **app_api:daemon:register**:

.. code-block:: bash

	app_api:daemon:register <name> <display-name> <accepts-deploy-id> <protocol> <host> <nextcloud_url> [--net NET] [--host HOST] [--ssl_key SSL_KEY] [--ssl_key_password SSL_KEY_PASSWORD] [--ssl_cert SSL_CERT] [--ssl_cert_password SSL_CERT_PASSWORD] [--]

Arguments
*********

	* ``name`` - unique name of the daemon (e.g. ``docker_local_sock``)
	* ``display-name`` - name of the daemon (e.g. ``My Local Docker``, will be displayed in the UI)
	* ``accepts-deploy-id`` - type of deployment (``docker-install`` or ``manual-install``)
	* ``protocol`` - protocol used to connect to the daemon (``unix-socket``, ``http`` or ``https``)
	* ``host`` - host of the daemon (e.g. ``/var/run/docker.sock`` for ``unix-socket`` protocol or ``host:port`` for ``http(s)`` protocol)
	* ``nextcloud_url`` - Nextcloud URL, Daemon config required option (e.g. ``https://nextcloud.local``)
	* ``--gpu`` - ``[optional]`` GPU device to expose to the daemon (e.g. ``/dev/dri``)

Options
*******

	* ``--net [network-name]``  - ``[required]`` network name to bind docker container to (default: ``host``)
	* ``--hostname HOST`` - ``[required]`` host to expose daemon to (defaults to ExApp appid)
	* ``--ssl_key SSL_KEY`` - ``[optional]`` path to SSL key file (local absolute path)
	* ``--ssl_password SSL_PASSWORD`` - ``[optional]`` SSL key password
	* ``--ssl_cert SSL_CERT`` - ``[optional]`` path to SSL cert file (local absolute path)
	* ``--ssl_cert_password SSL_CERT_PASSWORD`` - ``[optional]`` SSL cert password

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

.. note::
	Common configurations are tested by CI in our repository, see `workflows on github <https://github.com/cloud-py-api/app_api/blob/main/.github/workflows/tests-deploy.yml>`_.

Example
*******

Example of ``occ`` **app_api:daemon:register** command:

.. code-block:: bash

	sudo -u www-data php occ app_api:daemon:register docker_local_sock "My Local Docker" docker-install unix-socket /var/run/docker.sock "https://nextcloud.local" --net nextcloud


ExApp deployment
----------------

Second step is to deploy ExApp on registered daemon.
This can be done by ``occ`` CLI command **app_api:app:deploy**:

.. code-block:: bash

	app_api:app:deploy <appid> <daemon-config-name> [--info-xml INFO-XML] [-e|--env ENV] [--]

.. note::
	For development this step is skipped, as ExApp is deployed and started manually by developer.

.. warning::
	After successful deployment (pull, create and start container), there is a heartbeat check with 1 hour timeout (will be configurable).
	If command seems to be stuck, check if ExApp is running and accessible by Nextcloud instance.

Arguments
*********

	* ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in ``info.xml``)
	* ``daemon-config-name`` - unique name of the daemon (e.g. ``docker_local_sock``)

Options
*******

	* ``--info-xml INFO-XML`` **[required]** - path to info.xml file (url or local absolute path)
	* ``-e|--env ENV`` *[optional]* - additional environment variables (e.g. ``-e "MY_VAR=123" -e "MY_VAR2=456"``)

Deploy result JSON
******************

Example of deploy result JSON:

.. code-block::

	{
		"appid": "app_python_skeleton",
		"name":"App Python Skeleton",
		"daemon_config_name": "local_docker_sock",
		"version":"1.0.0",
		"secret":"***generated-secret***",
		"host":"app_python_skeleton",
		"port":"9001",
		"system_app": true
	}

This JSON structure is used in ExApp registration step for development.


Manual install for development
******************************

For development purposes, you can install ExApp manually.
There is a ``manual-install`` DeployConfig type, which can be used in case of development.
For ExApp registration with it you need to provide JSON app info with structure described before
using **app_api:app:register** ``--json-info`` option.

Deploy env variables
********************

Deploy env variables are used to configure ExApp container.
The following env variables are required and built automatically:

	* ``AA_VERSION`` - AppAPI version
	* ``APP_SECRET`` - generated shared secret used for AppAPI authentication
	* ``APP_ID`` - ExApp appid
	* ``APP_DISPLAY_NAME`` - ExApp display name
	* ``APP_VERSION`` - ExApp version
	* ``APP_PROTOCOL`` - protocol ExApp is listening on (http|https)
	* ``APP_HOST`` - host ExApp is listening on
	* ``APP_PORT`` - port ExApp is listening on (randomly selected by AppAPI)
	* ``IS_SYSTEM_APP`` - ExApp system app flag (true|false)
	* ``NEXTCLOUD_URL`` - Nextcloud URL to connect to

.. note::
	Additional envs can be passed using multiple ``--env ENV_NAME=ENV_VAL`` options

Docker daemon remote
********************

If you want to connect to remote docker daemon with TLS enabled, you need to provide SSL key and cert by provided options.
Important: before deploy you need to import ca.pem file using `occ security <https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html#security>`_ command:

``php occ security:certificates:import /path/to/ca.pem``

The daemon must be configured with ``protocol=http|https``, ``host=https://dockerapihost``, ``port=8443``.
DaemonConfig deploy options ``ssl_key`` and ``ssl_cert`` must be provided with local absolute paths to SSL key and cert files.
In case of password protected key or cert, you can provide ``ssl_key_password`` and ``ssl_cert_password`` options.
More info about how to configure daemon will be added soon.

ExApp registration
------------------

Final step is to register ExApp in Nextcloud.
This can be done by ``occ`` CLI command **app_api:app:register**:

.. code-block:: bash

	app_api:app:register <appid> <daemon-config-name> [-e|--enabled] [--force-scopes] [--]

Arguments
*********

	* ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in deployed container)
	* ``daemon-config-name`` - unique name of the daemon (e.g. ``docker_local_sock``)

Options
*******

	* ``-e|--enabled`` *[optional]* - enable ExApp after registration
	* ``--force-scopes`` *[optional]* - force scopes approval
	* ``--json-info JSON-INFO`` **[required]** - path to JSON file with deploy result (url or local absolute path)

With provided ``appid`` and ``daemon-config-name``, Nextcloud will retrieve ExApp info from deployed container and register it.
In case of ``manual-install`` DeployConfig type, ExApp info must be provided by ``--json-info`` option `as described before <#deploy-result-json-output>`_.

ExApp info.xml schema
---------------------

ExApp info.xml (`example <https://github.com/cloud-py-api/nc_py_api/blob/main/examples/as_app/talk_bot/appinfo/info.xml>`_) file is used to describe ExApp params.
It is used to generate ExApp docker container and to register ExApp in Nextcloud.
It has the same structure as Nextcloud appinfo/info.xml file, but with some additional fields:

.. code-block:: xml

	...
	<ex-app>
		<docker-install>
			<registry>ghcr.io</registry>
			<image>cloud-py-api/talk_bot</image>
			<image-tag>latest</image-tag>
		</docker-install>
		<scopes>
			<required>
				<value>TALK</value>
				<value>TALK_BOT</value>
			</required>
			<optional>
			</optional>
		</scopes>
		<protocol>http</protocol>
		<system>0</system>
	</ex-app>
	...
