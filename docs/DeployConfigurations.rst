.. _deploy-configs:

Deployment configurations
=========================

Currently, only one kind of application deployment is supported:
	* **Docker Deploy Daemon**

Docker Deploy Daemon
--------------------

Orchestrates the deployment of applications as Docker containers.

.. warning::

	The administrator is responsible for the security actions taken to configure the Docker daemon connected to Nextcloud.
	We recommend that you use the `AppAPI Docker Socket Proxy <https://github.com/cloud-py-api/docker-socket-proxy>`_ as the Deploy Daemon,
	it has stringent security rules and is easy to configure, `like in AIO <#nextcloud-in-docker-aio-all-in-one>`_.

There are several Docker Daemon Deploy configurations (example schemes):

	* Nextcloud and Docker on the **same host** (via socket or DockerSocketProxy)
	* Nextcloud on the host and Docker on a **remote** host (via DockerSocketProxy with HTTPS)
	* Nextcloud and **ExApps** in the **same Docker** (via DockerSocketProxy)
	* Nextcloud in AIO Docker and **ExApps** in the **same Docker** (via AIO DockerSocketProxy)

In the case of remote access to the Daemon, make certain that it's configured with **strong HaProxy password**.

.. note::

	These schemes are only examples of possible configurations.
	We recommend that you use the Docker Socket Proxy container as the Deploy Daemon.

NC & Docker on the Same-Host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The simplest configuration is when Nextcloud is installed on the host and Docker is on the same host and applications are deployed to it.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/nextcloud.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Nextcloud --> Daemon : /var/run/docker.sock
			Daemon --> Containers

			state Containers {
				ExApp1
				--
				ExApp2
				--
				ExApp3
			}
		}

		class Nextcloud nextcloud
		class Daemon docker
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

Suggested config values(template *Custom default*):
	1. Daemon host: ``/var/run/docker.sock``
	2. HTTPS checkbox: *not supported using docker socket*
	3. Network: ``host``
	4. HaProxy password: *not supported using docker socket*

---

Suggested way to communicate with Docker via `Docker Socket Proxy container <https://github.com/nextcloud/all-in-one/tree/main/Containers/docker-socket-proxy>`_.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/nextcloud.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Nextcloud --> DockerSocketProxy: by port
			Docker --> Containers
			Docker --> DockerSocketProxy : /var/run/docker.sock

			state Containers {
				DockerSocketProxy --> ExApp1
				DockerSocketProxy --> ExApp2
				DockerSocketProxy --> ExApp3
			}
		}

		class Nextcloud nextcloud
		class Docker docker
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

Suggested config values(template *Docker Socket Proxy*):
	1. Daemon host: ``localhost:2375``
		Choose **A** or **B** option:
			A. Docker Socket Proxy should be deployed with ``network=host`` and ``BIND_ADDRESS=127.0.0.1``
			B. Docker Socket Proxy should be deployed with ``network=bridge`` and it's port should be published to host's 127.0.0.1(e.g. **-p 127.0.0.1:2375:2375**)
	2. HTTPS checkbox: **disabled**
	3. Network: ``host``
	4. HaProxy password: **can be empty**

.. warning::

	Be careful with option ``A``, by default **Docker Socket Proxy** binds to ``*`` if ``BIND_ADDRESS`` is not specified during container creation.
	Check opened ports after finishing configuration(*or set HaProxy password*).


Docker on a remote host
^^^^^^^^^^^^^^^^^^^^^^^

Distributed configuration occurs when Nextcloud is installed on one host and Docker is located on a remote host, resulting in the deployment of applications on the remote host.

Benefit: no performance impact on Nextcloud host.

In this case, the AppAPI (Nextcloud) uses ``port`` to interact with remote Docker, which also could be a Docker Socket Proxy exposed with TLS.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/nextcloud.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Direction LR

			Host1 --> Host2 : by port

		state Host1 {
			Nextcloud
		}

		state Host2 {
			[*] --> DockerSocketProxy : by port
			Daemon --> Containers

			state Containers {
				[*] --> DockerSocketProxy : /var/run/docker.sock
				DockerSocketProxy --> ExApp1
				DockerSocketProxy --> ExApp2
				DockerSocketProxy --> ExApp3
			}
		}

		class Nextcloud nextcloud
		class Daemon docker
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

NC & ExApps in the same Docker
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Applications are deployed in the same docker where Nextcloud resides.

Suggested way to communicate with Docker: via ``docker-socket-proxy``.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/nextcloud.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Daemon --> Containers

			state Containers {
				[*] --> DockerSocketProxy : /var/run/docker.sock
				Nextcloud --> DockerSocketProxy: by port
				--
				DockerSocketProxy --> ExApp1
				DockerSocketProxy --> ExApp2
			}
		}

		class Nextcloud nextcloud
		class Daemon docker
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

Nextcloud in Docker AIO (all-in-one)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

In case of AppAPI is in Docker AIO setup (installed in Nextcloud container).

.. note::

	AIO Docker Socket Proxy container must be enabled.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef docker2 fill: #1f97ee, color: transparent, font-size: 20px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/nextcloud.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Daemon --> Containers

			state Containers {
				[*] --> NextcloudAIOMasterContainer : /var/run/docker.sock
				[*] --> DockerSocketProxy : /var/run/docker.sock
				NextcloudAIOMasterContainer --> Nextcloud
				AppAPI --> Nextcloud : installed in
				Nextcloud --> DockerSocketProxy
				DockerSocketProxy --> ExApp1
				DockerSocketProxy --> ExApp2
				DockerSocketProxy --> ExApp3
			}
		}

		class Nextcloud nextcloud
		class Daemon docker
		class Daemon2 docker2
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

AppAPI will automatically create default default DaemonConfig to use AIO Docker Socket Proxy as orchestrator to create ExApp containers.

.. note::

	Default DaemonConfig will be created only if the default DaemonConfig is not already registered.


Default AIO Deploy Daemon
*************************

Nextcloud AIO has a specifically created Docker Socket Proxy container to be used as the Deploy Daemon in AppAPI.
It has `fixed parameters <https://github.com/cloud-py-api/app_api/blob/main/lib/DeployActions/AIODockerActions.php#L52-L74)>`_:

* Name: ``docker_aio``
* Display name: ``AIO Docker Socket Proxy``
* Accepts Deploy ID: ``docker-install``
* Protocol: ``http``
* Host: ``nextcloud-aio-docker-socket-proxy:2375``
* GPUs support: ``false``
* Network: ``nextcloud-aio``
* Nextcloud URL (passed to ExApps): ``https://$NC_DOMAIN``

.. note::
	If ``NEXTCLOUD_ENABLE_DRI_DEVICE=true`` is set - separate DaemonConfig (``docker_aio_gpu``) will be created with ``gpus=true``.

Docker Socket Proxy security
****************************

AIO Docker Socket Proxy has strictly limited access to the Docker APIs described in `HAProxy configuration <https://github.com/nextcloud/all-in-one/blob/main/Containers/docker-socket-proxy/haproxy.cfg>`_.
