================================
Docker deployment configurations
================================

There are several ExApp daemon configurations:

	* Nextcloud in host and Daemon (Docker) in the same host (by socket or port)
	* Nextcloud in host and Daemon (Docker) on remote host (by port)
	* Nextcloud in container (Docker) and Daemon (Docker) in the same host (by socket or port)
	* Nextcloud in container (Docker) and Daemon (Docker) is in container (Docker in Docker) - by socket or port

Detailed information and pictures are listed below.


Nextcloud in host and Daemon (Docker) in the same host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

The simplest configuration is when Nextcloud is installed in host and ExApp daemon (Docker) is in the same host.

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, stroke: #364c53, stroke-width: 1px, background: url(https://www.docker.com/wp-content/uploads/2022/01/Docker-Logo-White-RGB_Horizontal-730x189-1.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, stroke: #045987, stroke-width: 1px, background: url(https://nextcloud.com/wp-content/uploads/2023/02/logo_nextcloud_white.svg) no-repeat center center / contain
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

In this case, the ExApp daemon (Docker) is connected to the Nextcloud by socket ``/var/run/docker.sock``.
Make sure that Nextcloud webserver user has enough permissions to access the socket.

Nextcloud in host and Daemon (Docker) on remote host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, stroke: #364c53, stroke-width: 1px, background: url(https://www.docker.com/wp-content/uploads/2022/01/Docker-Logo-White-RGB_Horizontal-730x189-1.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, stroke: #045987, stroke-width: 1px, background: url(https://nextcloud.com/wp-content/uploads/2023/02/logo_nextcloud_white.svg) no-repeat center center / contain
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


Nextcloud in container (Docker) and Daemon (Docker) in the same host
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, stroke: #364c53, stroke-width: 1px, background: url(https://www.docker.com/wp-content/uploads/2022/01/Docker-Logo-White-RGB_Horizontal-730x189-1.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: transparent, stroke: #045987, stroke-width: 1px, background: url(https://nextcloud.com/wp-content/uploads/2023/02/logo_nextcloud_white.svg) no-repeat center center / contain
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Daemon --> Containers

			state Containers {
				Nextcloud
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


Nextcloud in container (Docker) and Daemon (Docker) is in container (Docker in Docker)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

.. mermaid::

	stateDiagram-v2
		classDef docker fill: #1f97ee, color: transparent, stroke: #364c53, stroke-width: 1px, background: url(https://www.docker.com/wp-content/uploads/2022/01/Docker-Logo-White-RGB_Horizontal-730x189-1.png) no-repeat center center / contain
		classDef nextcloud fill: #006aa3, color: white, stroke: #045987, stroke-width: 1px
		classDef python fill: #1e415f, color: white, stroke: #364c53, stroke-width: 1px

		Host

		state Host {
			Daemon --> Containers

			state Containers {
				[*] --> Nextcloud

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
		class Daemon2 docker
		class ExApp1 python
		class ExApp2 python
		class ExApp3 python
