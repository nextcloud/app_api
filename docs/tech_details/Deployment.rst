Deployment
==========

Overview
--------

AppAPI ExApps deployment process in short consists of 3 steps:

1. `DaemonConfig registration`_
2. `ExApp deployment`_
3. `ExApp registration`_

.. _occ_daemon_config_registration:

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

	app_api:daemon:register <name> <display-name> <accepts-deploy-id> <protocol> <host> <nextcloud_url> [--net NET] [--haproxy_password PASSWORD] [--]

Arguments
*********

	* ``name`` - unique name of the daemon (e.g. ``docker_local_sock``)
	* ``display-name`` - name of the daemon (e.g. ``My Local Docker``, will be displayed in the UI)
	* ``accepts-deploy-id`` - type of deployment (``docker-install`` or ``manual-install``)
	* ``protocol`` - protocol used to connect to the daemon (``http`` or ``https``)
	* ``host`` - host of the daemon (e.g. ``/var/run/docker.sock`` or ``host:port``)
	* ``nextcloud_url`` - Nextcloud URL, Daemon config required option (e.g. ``https://nextcloud.local``)

Options
*******

	* ``--net [network-name]``  - ``[required]`` network name to bind docker container to (default: ``host``)
	* ``--haproxy_password PASSWORD`` - ``[optional]`` password if ``AppAPI Docker Socket Proxy`` is used
	* ``--gpu`` - ``[optional]`` GPU device to expose to the daemon (e.g. ``/dev/dri``)

.. note::
	Common configurations are tested by CI in our repository, see `workflows on github <https://github.com/cloud-py-api/app_api/blob/main/.github/workflows/tests-deploy.yml>`_.

Example
*******

Example of ``occ`` **app_api:daemon:register** command:

.. code-block:: bash

	sudo -u www-data php occ app_api:daemon:register docker_local_sock "My Local Docker" docker-install http /var/run/docker.sock "https://nextcloud.local" --net nextcloud


ExApp deployment
----------------

Second step is to deploy ExApp on registered daemon.
This can be done by ``occ`` CLI command **app_api:app:deploy**:

.. code-block:: bash

	app_api:app:deploy <appid> <daemon-config-name> [--info-xml INFO-XML] [-e|--env ENV] [--]

.. note::
	For development this step is skipped, as ExApp is deployed and started manually by developer.

.. warning::
	After successful deployment (pull, create and start container), there is a heartbeat check with 90 seconds timeout (will be configurable).

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

For all examples and applications we release we usually add manual_install command in it's makefile for easier development.

.. code-block::

	php occ app_api:app:register nc_py_api manual_install --json-info \
            "{\"appid\":\"nc_py_api\",\"name\":\"nc_py_api\",\"daemon_config_name\":\"manual_install\",\"version\":\"1.0.0\",\"secret\":\"12345\",\"port\":$APP_PORT,\"scopes\":[\"SYSTEM\", \"FILES\", \"FILES_SHARING\", \"USER_INFO\", \"USER_STATUS\", \"NOTIFICATIONS\", \"WEATHER_STATUS\", \"TALK\"],\"system_app\":1}" \
            --force-scopes

.. note:: **Deployment/Startup of App should be done by developer when manual_install DeployConfig type is used.**

Deploy env variables
********************

Deploy env variables are used to configure ExApp container.
The following env variables are required and built automatically:

	* ``AA_VERSION`` - AppAPI version
	* ``APP_SECRET`` - generated shared secret used for AppAPI authentication
	* ``APP_ID`` - ExApp appid
	* ``APP_DISPLAY_NAME`` - ExApp display name
	* ``APP_VERSION`` - ExApp version
	* ``APP_HOST`` - host ExApp is listening on
	* ``APP_PORT`` - port ExApp is listening on (randomly selected by AppAPI)
	* ``APP_PERSISTENT_STORAGE`` - path to mounted volume for persistent data storage between ExApp updates
	* ``IS_SYSTEM_APP`` - ExApp system app flag (true|false)
	* ``NEXTCLOUD_URL`` - Nextcloud URL to connect to

.. note::
	Additional envs can be passed using multiple ``--env ENV_NAME=ENV_VAL`` options

ExApp registration
------------------

Final step is to register ExApp in Nextcloud.
This can be done by ``occ`` CLI command **app_api:app:register**:

.. code-block:: bash

	app_api:app:register <appid> <daemon-config-name> [--force-scopes] [--]

Arguments
*********

	* ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in deployed container)
	* ``daemon-config-name`` - unique name of the daemon (e.g. ``docker_local_sock``)

Options
*******

	* ``--force-scopes`` *[optional]* - force scopes approval
	* ``--json-info JSON-INFO`` **[required]** - path to JSON file with deploy result (url or local absolute path)

With provided ``appid`` and ``daemon-config-name``, Nextcloud will retrieve ExApp info from deployed container and register it.
In case of ``manual-install`` DeployConfig type, ExApp info must be provided by ``--json-info`` option `as described before <#deploy-result-json-output>`_.

Application installation scheme
-------------------------------

1. AppAPI deploys the application and launches it.
2. AppAPI for `N` seconds (default ``90``) checks the ``/heartbeat`` endpoint with ``GET`` request.
3. AppAPI sends a ``POST`` to the ``/init`` endpoint.

	.. note:: if ExApp do not implements ``/init`` endpoint and
		AppAPI receives 501 or 404 status error, AppAPI enables the application by going to point 5.

4. **ExApp** sends an integer from ``0`` to ``100`` to the OCS endpoint ``apps/app_api/apps/status`` indicating the initialization progress. After sending ``100``, the application is considered initialized.
5. AppAPI sends a PUT to the ``/enabled`` endpoint.

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
			<value>TALK</value>
			<value>TALK_BOT</value>
		</scopes>
		<system>0</system>
	</ex-app>
	...
