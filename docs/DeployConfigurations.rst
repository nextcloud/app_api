.. _deploy-configs:

Deployment configurations
=========================

Currently, only one kind of application deployment is supported:
	* **Docker Deploy Daemon**

Docker Deploy Daemon
--------------------

Provides the deployment of applications as Docker containers.

There are several Docker Daemon Deploy configurations:

	* Nextcloud and Docker on the **same host** (via socket or port)
	* Nextcloud on the host and Docker on a **remote** host (via port)
	* Nextcloud and **ExApps** in the **same Docker** (via socket or port)
	* Nextcloud in a Docker and **ExApps** in the **child Docker** (DiD) (via socket)
	* Nextcloud in AIO Docker and **ExApps** in the **same Docker** (via socket proxy)

For each configuration that uses a socket, please ensure that the Nextcloud webserver user has sufficient permissions to access it.
In the case of remote access to the Daemon, make certain that it's configured with **ssl_key**, **ssl_cert**, and **ca.cert**, and that the latter is imported into Nextcloud.

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

Suggested way to communicate with Docker: via ``socket``.

Docker on a remote host
^^^^^^^^^^^^^^^^^^^^^^^

Distributed configuration occurs when Nextcloud is installed on one host and Docker is located on a remote host, resulting in the deployment of applications on the remote host.

Benefit: no performance impact on Nextcloud host.

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

In this case, the AppAPI (Nextcloud) uses ``port`` to interact with Docker.

NC & ExApps in the same Docker
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Applications are deployed in the same docker where Nextcloud resides.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/nextcloud.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Daemon --> Containers

			state Containers {
				[*] --> Nextcloud : /var/run/docker.sock
				--
				ExApp1
				--
				ExApp2
			}
		}

		class Nextcloud nextcloud
		class Daemon docker
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

Suggested way to communicate with Docker: via ``socket``.

NC in Docker and ExApps in child Docker (Docker in Docker)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

In this scenario, Nextcloud is installed within a container, and a separate Daemon (Docker) is also contained within the Nextcloud container.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef docker2 fill: #1f97ee, color: transparent, font-size: 20px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_api/main/docs/img/docker.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: white, stroke: #045987, stroke-width: 1px
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Daemon --> Containers

			state Containers {
				[*] --> Nextcloud : /var/run/docker.sock

				state Nextcloud {
					Daemon2 --> Containers2

					state Containers2 {
						ExApp1
						--
						ExApp2
						--
						ExApp3
					}
				}
			}
		}

		class Nextcloud nextcloud
		class Daemon docker
		class Daemon2 docker2
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python

In this case, the AppAPI (Nextcloud) uses ``socket`` to interact with Docker.

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
