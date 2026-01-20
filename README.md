<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Nextcloud AppAPI

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/app_api)](https://api.reuse.software/info/github.com/nextcloud/app_api)
[![Tests - Deploy](https://github.com/nextcloud/app_api/actions/workflows/tests-deploy.yml/badge.svg)](https://github.com/nextcloud/app_api/actions/workflows/tests-deploy.yml)
[![Tests](https://github.com/nextcloud/app_api/actions/workflows/tests.yml/badge.svg)](https://github.com/nextcloud/app_api/actions/workflows/tests.yml)

AppAPI is the Nextcloud component that enables **External Apps (ExApps)**.

ExApps are Nextcloud apps whose backend runs **outside** the Nextcloud PHP process (typically as a Docker
container). AppAPI provides the APIs and lifecycle management so these external backends can still
integrate with Nextcloud users, permissions and the web UI.

AppAPI is **only useful if you want to install or develop External Apps**.

## If you are here because of the warning “default deploy daemon is not set”

You might have seen this in the admin overview/security checks:

> AppAPI default deploy daemon is not set. Please register a default deploy daemon …

This warning means:

- AppAPI is enabled, **but no Deploy Daemon is configured**, so Nextcloud cannot install/run External Apps yet.

You have two valid options:

1. **You do not want External Apps**
	- Disable AppAPI in **Apps → Tools → AppAPI**, or with:
		- `occ app:disable app_api`
	- The warning will disappear.

2. **You want to install External Apps**
	- Open **Settings → Administration → AppAPI**
	- Register a Deploy Daemon and set it as the default
	- Use **Check connection** and **Test deploy** to verify the setup

## Key concepts (short glossary)

- **External App (ExApp)**: a Nextcloud app where the backend runs as a separate service (usually a container),
  but is still installed/managed from Nextcloud and integrates with the Nextcloud UI.
- **Deploy Daemon**: the service Nextcloud talks to in order to install, start/stop, and reach ExApps.
  Without a Deploy Daemon, AppAPI cannot deploy ExApps.

## Deploy Daemon options

### HaRP (recommended for Nextcloud 32+)

**HaRP** (High-performance AppAPI Reverse Proxy) is the newer and recommended Deploy Daemon.
It is a reverse proxy system designed specifically for ExApps:

- Proxies access to the Docker Engine used to create ExApp containers
- Routes requests directly to ExApps (bypassing the Nextcloud PHP process), improving performance and enabling WebSockets
- Uses FRP (Fast Reverse Proxy) tunnels so ExApp containers do not need to expose ports to the host — this simplifies networking and provides NAT traversal

Repository: https://github.com/nextcloud/HaRP

### Docker Socket Proxy (DSP) — legacy

**Docker Socket Proxy** (often shortened as **DSP**) is the classic Deploy Daemon implementation.
It is a security-hardened proxy in front of the Docker Engine socket/API and is protected by basic
authentication and brute-force protection.

> **Note:** DSP is being deprecated in favor of HaRP and is scheduled for removal in Nextcloud 35.
> New installations should use HaRP.

Repository: https://github.com/nextcloud/docker-socket-proxy

## Security notes (for administrators)

Configuring a Deploy Daemon means allowing Nextcloud to orchestrate application containers. Keep these points in mind:

- Run HaRP/DSP in a trusted network and do not expose it to the public internet.
- Use strong secrets (`HP_SHARED_KEY` for HaRP, `NC_HAPROXY_PASSWORD` for DSP).
- For remote setups or untrusted networks, use TLS where supported and restrict access with firewall rules.
- Only deploy ExApps you trust, and keep their images up to date.

## Why AppAPI exists (what it is useful for)

AppAPI is designed to make it easier to build and run ExApps in a way that is:

1. **Stable for admins**: ExApps integrate through defined interfaces instead of tightly coupling to server internals.
2. **More isolated**: ExApps run out-of-process and interact with Nextcloud through controlled APIs.
3. **Suitable for heavy workloads**: ExApps can run on separate hardware (including GPU-enabled hosts).
4. **Language-friendly**: ExApps can be written in languages other than PHP (Python/Node/Go/…).

## Documentation

Latest documentation can be found here:

1. Admin manual:
	- AppAPI and External Apps: https://docs.nextcloud.com/server/latest/admin_manual/exapps_management/AppAPIAndExternalApps.html
	- Deployment configurations: https://docs.nextcloud.com/server/latest/admin_manual/exapps_management/DeployConfigurations.html
2. Developer manual:
	- ExApp development: https://docs.nextcloud.com/server/latest/developer_manual/exapp_development/index.html

### Support

We appreciate any support for this project:

- ⭐ Star our work on GitHub
- ❗ Create an issue or feature request
- 💁 Resolve an issue and open a pull request
- 🧑‍💻 Build and publish ExApps using AppAPI


Thank you for helping improve ExApps and their ecosystem.
