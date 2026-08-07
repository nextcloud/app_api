<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Minimal ExApp

A reference External App implemented against the raw AppAPI contract: one Python file, standard
library only, no frameworks and no nc_py_api. If you want to write an ExApp in Go, Rust, Node or
anything else, port `main.py`; the contract is exactly what it implements. If you are writing
Python, you will normally want [nc_py_api](https://github.com/cloud-py-api/nc_py_api) instead,
which implements all of this for you.

Layout:

- `main.py` - the whole app: heartbeat, enabled, init with progress reporting, auth validation in
  both directions, one endpoint calling a Nextcloud OCS API.
- `appinfo/info.xml` - the manifest; see
  [docs/appapi/exapp-contract.md](../../docs/appapi/exapp-contract.md) for the reference.
- `start.sh` - vendored unchanged from the [HaRP](https://github.com/nextcloud/HaRP) repository
  (`exapps_dev/start.sh`): writes the frpc configuration and starts the tunnel when deployed
  through a HaRP daemon.
- `Dockerfile` - Alpine image with `frp`, following HaRP's "Adapting ExApps" instructions.
- `Makefile` - the two development loops, see below.

How to develop against it and how to set up the environment:
[docs/appapi/dev-environment.md](../../docs/appapi/dev-environment.md) and
[docs/appapi/exapp-development.md](../../docs/appapi/exapp-development.md).

## Fast loop (manual-install): run the app as a local process

```bash
make run                # starts main.py on APP_PORT (default 9080); keep it running
make register-manual    # in a second terminal: registers it on the manual-install daemon
```

`register-manual` (like `register-docker`) starts with a best-effort unregister, so it replaces any
existing installation of this app, including a docker-installed one.

Iterate by editing `main.py` and restarting `make run`; no re-registration needed unless the
manifest (routes, version) changes.

## Production-like loop (docker-install through HaRP)

```bash
make build              # docker build -t example.local/minimal_exapp:<image-tag>
make register-docker    # maps registry example.local to "local" on the daemon and registers
```

After code changes: bump BOTH `<version>` and `<image-tag>` in `appinfo/info.xml` (keep them
equal; `make build` refuses to run when they differ, because AppAPI deploys `<image-tag>` while
`app:update` compares `<version>`), then

```bash
make build update-docker
```

`app_api:app:update` redeploys the container from the rebuilt image and preserves the app secret,
port, configuration and data volume. Same-version updates are a no-op by design, which is why the
version bump is required.

Variables (override like `make NEXTCLOUD_CONTAINER=my-nextcloud-container register-docker`): see
the top of the `Makefile`. Defaults match a stock
[nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev) setup with the daemons
from the dev-environment runbook.
