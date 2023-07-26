================================
Docker deployment configurations
================================

There are several Daemon configurations:

	* Nextcloud in host and Daemon (Docker) in the same host (by socket or port)
	* Nextcloud in host and Daemon (Docker) on remote host (by port)
	* Nextcloud in container (Docker) and Daemon (Docker) in the same host (by socket or port)
	* Nextcloud in container (Docker) and Daemon (Docker) is in container (Docker in Docker) - by socket or port

For each configuration using socket make sure that Nextcloud webserver user has enough permissions to access it.
In case of remote remote access to Daemon, make sure that it configured with ssl_key, ssl_cert and ca.cert is imported to Nextcloud.

Nextcloud in host and Daemon in the same host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The simplest configuration is when Nextcloud is installed in host and ExApp daemon (Docker) is in the same host.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/docker.svg) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/nextcloud.svg) no-repeat center center / contain
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

In this case, the ExApp daemon (Docker) can be connected to the Nextcloud by socket ``/var/run/docker.sock``.

Nextcloud in host and Daemon on remote host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Distributed configuration is when Nextcloud is installed in host and ExApp daemon (Docker) is on remote host.
Benefit: no performance impact on Nextcloud host.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/docker.svg) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/nextcloud.svg) no-repeat center center / contain
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



Nextcloud in container and Daemon in the same host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/docker.svg) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, font-size: 34px, stroke: #045987, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/nextcloud.svg) no-repeat center center / contain
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


Nextcloud in container and Daemon is in container (Docker in Docker)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, font-size: 34px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/docker.svg) no-repeat center center / contain
		classDef docker2 fill: #1f97ee, color: transparent, font-size: 20px, stroke: #364c53, stroke-width: 1px, background: url(https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/docs/img/docker.svg) no-repeat center center / contain
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

In this case, Nextcloud is installed in container and second separate Daemon (Docker) is in Nextcloud container.
