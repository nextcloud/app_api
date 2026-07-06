<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# AppAPI agent guide

This is the start point for working with **`app_api`**, both **operating** AppAPI (installing and managing
External Apps on a real Nextcloud) and **developing** this repo. It is written so an AI assistant can pick it
up as context and help a colleague set up, verify and troubleshoot AppAPI correctly, and so a new contributor
(human or AI) can build and change the code.

If you are helping someone **install or run** AppAPI, start at [Quickstart](#2-quickstart-zero-to-a-working-exapp)
and [Troubleshooting](#10-troubleshooting-symptom-first). If you are helping **develop** `app_api`, start at
[Developing app_api](#12-developing-app_api-start-here).

Applies to **Nextcloud 33, 34 and 35**. This repo's `main` is the NC35 dev line; released majors live on
`stableXX` branches. Behavior that differs by version is called out in [Version notes](#11-version-notes-nc33--34--35).
This is a **living document**: when you change AppAPI's behavior, update the relevant section in the same
change. Keep it portable (see [Keep this file portable](#15-keep-this-file-portable-and-current)).

For building the ExApp side (Python), see the sibling project **nc_py_api** (`cloud-py-api/nc_py_api`).

**Detailed runbooks** for specific topologies live under `docs/appapi/` and are linked from the sections below:
[Kubernetes](docs/appapi/kubernetes.md), [ExApps on a separate host](docs/appapi/remote-daemon.md), and
[Nextcloud AIO](docs/appapi/aio.md). They hold depth that would bloat this hub; open the relevant one when
working on that topology.

## Table of contents

1. [What AppAPI is](#1-what-appapi-is)
2. [Quickstart: zero to a working ExApp](#2-quickstart-zero-to-a-working-exapp)
3. [Deploy daemons: which to use](#3-deploy-daemons-which-to-use)
4. [Setup cases (topologies)](#4-setup-cases-topologies)
5. [`occ app_api:daemon:register` reference](#5-occ-app_apidaemonregister-reference)
6. [ExApp lifecycle (occ)](#6-exapp-lifecycle-occ)
7. [Operating AppAPI](#7-operating-appapi)
8. [App store / fetcher](#8-app-store--fetcher)
9. [Runtime and the ExApp contract](#9-runtime-and-the-exapp-contract)
10. [Troubleshooting (symptom-first)](#10-troubleshooting-symptom-first)
11. [Version notes (NC33 / 34 / 35)](#11-version-notes-nc33--34--35)
12. [Developing app_api (start here)](#12-developing-app_api-start-here)
13. [Key files](#13-key-files)
14. [Related links](#14-related-links)
15. [Keep this file portable and current](#15-keep-this-file-portable-and-current)

Throughout, `occ` means the Nextcloud server console. Where it runs depends on your install:

- **Docker / docker compose**: inside the Nextcloud container, e.g.
  `docker exec -u www-data <nextcloud-container> php occ <command>` (find the container with `docker compose ps`
  or `docker ps`).
- **Snap**: `nextcloud.occ <command>`.
- **Bare-metal / other**: `sudo -u www-data php occ <command>` (or `./occ`).

Every AppAPI option below is long-form only (there are no short flags).

## 1. What AppAPI is

AppAPI is the Nextcloud component that enables **External Apps (ExApps)**: apps whose backend runs **outside**
the Nextcloud PHP process (usually as a Docker container), while still integrating with Nextcloud users,
permissions and the web UI.

- **This repo (`app_api`)**: PHP backend + Vue frontend. It stores daemon configuration, manages ExApp
  lifecycle (install, enable, disable, update, remove), and authorizes/routes traffic to ExApps.
- **Deploy Daemon**: the external service Nextcloud talks to in order to install, start/stop and reach ExApps.
  Without a configured Deploy Daemon, AppAPI cannot deploy anything, and Nextcloud shows the admin warning
  "AppAPI default deploy daemon is not set".
- **HaRP** (`nextcloud/HaRP`): the recommended daemon (NC32+), a high-performance reverse proxy that proxies
  the Docker Engine, routes requests straight to ExApps (bypassing PHP, enabling WebSockets), and uses FRP
  tunnels so ExApps need not expose host ports.
- **nc_py_api**: the Python framework used to write ExApps that call back into Nextcloud through AppAPI.

ExApps are **trusted, first-class apps**, comparable to PHP apps running inside Nextcloud: they authenticate
with a per-install app secret and integrate with user sessions. Install only ExApps you trust, exactly as you
would with regular Nextcloud apps.

AppAPI is only useful if you want to install or develop External Apps. If you do not, you can disable it
(`occ app:disable app_api`) and the "default deploy daemon" warning disappears.

## 2. Quickstart: zero to a working ExApp

The golden path on a Docker-based Nextcloud, using HaRP. This is the setup most colleagues want. Replace the
placeholders in angle brackets; never paste a real secret into a shared file.

**Prerequisites**: Nextcloud 33+ with admin access; a Docker Engine reachable from where you run HaRP; HaRP
able to reach your Nextcloud URL.

- **On Nextcloud AIO**: HaRP is auto-registered as the `harp_aio` daemon (NC33+); skip Steps 2-6 and go to
  Step 7. See [`docs/appapi/aio.md`](docs/appapi/aio.md).
- **If Nextcloud itself is not in Docker** (snap/bare-metal): in Step 3 omit `--network` and publish HaRP's
  ports; in Step 5 drop `--net`, set `host` to `localhost:8780` and `--harp_frp_address localhost:8782`. Run
  `occ` natively (see the note above).

**Step 1. Enable AppAPI.**

```bash
occ app:enable app_api
```

**Step 2. Pick one shared secret.** HaRP and AppAPI authenticate to each other with a single shared key. Use
a strong ASCII string and reuse the exact same value in Step 3 and Step 5.

```bash
export HP_SHARED_KEY="<CHOOSE_A_STRONG_ASCII_SECRET>"
```

**Step 3. Start HaRP** (the Deploy Daemon). Put it on the same Docker network as Nextcloud so they can reach
each other by container name.

```bash
docker run -d \
    --name appapi-harp -h appapi-harp \
    --restart unless-stopped \
    --network <your-nextcloud-docker-network> \
    -e HP_SHARED_KEY="$HP_SHARED_KEY" \
    -e NC_INSTANCE_URL="<nextcloud-url-reachable-from-harp>" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$(pwd)/certs:/certs" \
    -p 8780:8780 \
    -p 8782:8782 \
    ghcr.io/nextcloud/nextcloud-appapi-harp:release
```

- Find `<your-nextcloud-docker-network>` with `docker network ls` (a compose install usually names it
  `<project>_default`, e.g. `nextcloud_default`).
- `HP_SHARED_KEY` and `NC_INSTANCE_URL` are the only required env vars. Set `NC_INSTANCE_URL` to this
  Nextcloud's URL as reachable **from inside the Docker network** (your public URL usually works; never
  `localhost`). Reuse the same value in Step 5.
- Ports: `8780` = ExApps HTTP frontend (the reverse-proxy target in Step 4). `8782` = FRP TCP frontend.
  `8781` = optional HTTPS (needs `/certs`). Behind a reverse proxy, add `-e HP_TRUSTED_PROXY_IPS="<proxy-cidr>"`.

> **Security.** Mounting the Docker socket gives HaRP root-equivalent control of the host: run it only on a
> host you trust, from the official image. The `-p` mappings publish on all interfaces by default; on a single
> host prefer `-p 127.0.0.1:8780:8780` and reach `8782` over the internal Docker network, or firewall both
> ports so they are never on a public interface. Do not set `HP_FRP_DISABLE_TLS` on an untrusted network.

**Step 4. Route `/exapps/` to HaRP on your Nextcloud reverse proxy.** Browsers reach ExApp frontends directly
through HaRP (bypassing PHP; required for WebSockets/streaming). On whatever proxy already terminates TLS for
Nextcloud, forward `/exapps/` to HaRP `:8780`. **The daemon checks and Test deploy in Step 6 pass without this
rule**, but ExApp UIs and WebSocket endpoints will be unreachable from the browser.

nginx:

```nginx
location /exapps/ {
    proxy_pass http://<harp-host>:8780;   # container name if on the same Docker network
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Apache needs `mod_proxy_wstunnel` for the WebSocket upgrade (a plain `ProxyPass` proxies HTTP but silently
drops WebSockets):

```apache
# a2enmod proxy proxy_http proxy_wstunnel rewrite
RewriteEngine On
RewriteCond %{HTTP:Upgrade} websocket [NC]
RewriteRule ^/exapps/(.*)$ ws://<harp-host>:8780/exapps/$1 [P,L]
ProxyPass        /exapps/ http://<harp-host>:8780/exapps/
ProxyPassReverse /exapps/ http://<harp-host>:8780/exapps/
```

Then make sure HaRP has `-e HP_TRUSTED_PROXY_IPS="<proxy-cidr>"` (Step 3). Full examples:
https://github.com/nextcloud/HaRP

**Step 5. Register HaRP in AppAPI and make it the default daemon.** The positional order is
`name display-name accepts-deploy-id protocol host nextcloud_url`.

```bash
occ app_api:daemon:register \
    harp1 "HaRP" docker-install http <harp-host>:8780 <nextcloud-url-reachable-from-harp> \
    --net <your-nextcloud-docker-network> \
    --harp \
    --harp_frp_address <harp-host>:8782 \
    --harp_shared_key "$HP_SHARED_KEY" \
    --set-default
```

- Use the **same** URL for the `nextcloud_url` positional here and `NC_INSTANCE_URL` in Step 3.
- The single most common failure is a **shared-key mismatch**: `--harp_shared_key` must be byte-identical to
  HaRP's `HP_SHARED_KEY` from Step 2. Use `https` + port `8781` instead of `http` + `8780` only if you are
  reaching HaRP across an untrusted network with certs configured.

**Step 6. Verify the daemon.**

```bash
occ app_api:daemon:list
```

In the UI, open **Settings --> Administration --> AppAPI** and use **Check connection** and **Test deploy**
(the latter installs and removes a real test ExApp, exercising image pull + run, not just connectivity).
The admin setup checks `DaemonCheck` (daemon reachable, default set) and `HarpVersionCheck` (HaRP new enough)
should be green. Note: green checks confirm the internal Nextcloud-to-HaRP path only; the **browser** path
still needs the `/exapps/` proxy rule from Step 4.

**Step 7. Install an ExApp.** Browse installable ExApps in the UI store (**Settings --> Administration -->
AppAPI**) or at https://apps.nextcloud.com/ to find its id; there is no `occ` command
that lists store apps. Then install it (this pulls the app from the App Store and uses the default daemon):

```bash
occ app_api:app:register <appid> --wait-finish
```

**Step 8. Confirm it is running.**

```bash
occ app_api:app:list                 # shows: <appid> (<name>): <version> [enabled]
docker ps --filter name=nc_app_      # ExApp containers are prefixed nc_app_
```

That is a working ExApp. From here, [Operating AppAPI](#7-operating-appapi) covers day-2 management and
[Troubleshooting](#10-troubleshooting-symptom-first) covers what to check when a step fails.

## 3. Deploy daemons: which to use

A HaRP daemon is **not** identified by its deploy type. Both HaRP and the legacy Docker Socket Proxy use the
`docker-install` type; a daemon is HaRP when its `deploy_config` carries a `harp` sub-array
(`HarpService::isHarp()`). The `--harp` flag on registration is what creates that array.

| Deploy type | Class | When to use | Status |
|---|---|---|---|
| `docker-install` + `--harp` | `DockerActions` | Default. Docker host (local or remote) via HaRP | Recommended (NC32+) |
| `docker-install` (no `--harp`) | `DockerActions` | Legacy Docker Socket Proxy (DSP) | Deprecated (see [Version notes](#11-version-notes-nc33--34--35)) |
| `kubernetes-install` | `KubernetesActions` | Kubernetes cluster, always via HaRP | Supported (NC34+) |
| `manual-install` | `ManualActions` | Local development; ExApp runs outside any orchestration | Dev only |

GPU: add `--compute_device cuda` (NVIDIA) or `--compute_device rocm` (AMD) to any daemon for AI ExApps
(`cpu` is the default).

## 4. Setup cases (topologies)

| Case | How to register | Notes |
|---|---|---|
| HaRP on the same Docker host as Nextcloud | `docker-install --harp`, `host` = `<harp-host>:8780`, `--harp_frp_address <harp-host>:8782` | Most common single-host setup (the Quickstart) |
| ExApps on a **separate host** | HaRP near NC + FRP-tunneled remote engine (`--harp_docker_socket_port`), or HaRP on the ExApp host | Runbook: `docs/appapi/remote-daemon.md` |
| Kubernetes (NC34+) | `--k8s --harp` (forces `kubernetes-install`) + `--k8s_expose_type` | FRP address not required for K8s; all K8s ops go through HaRP. Runbook: `docs/appapi/kubernetes.md` |
| Nextcloud AIO | auto-registered `harp_aio` daemon (`nextcloud-aio-harp:8780`) when HaRP is enabled (NC33+) | Managed by AIO. Runbook: `docs/appapi/aio.md` |
| Local development | `manual-install` | ExApp process runs on your machine; pair with nc_py_api dev mode |
| Legacy Docker Socket Proxy | `docker-install` (no `--harp`) + `--haproxy_password` | Deprecated; migrate to HaRP |

## 5. `occ app_api:daemon:register` reference

Source: `lib/Command/Daemon/RegisterDaemon.php`. Positional arguments (all required, in order):

| # | Argument | Meaning |
|---|---|---|
| 1 | `name` | Unique daemon name/id |
| 2 | `display-name` | Human-readable name shown in the UI |
| 3 | `accepts-deploy-id` | `manual-install`, `docker-install`, or `kubernetes-install` |
| 4 | `protocol` | `http` or `https` (how Nextcloud connects to the daemon) |
| 5 | `host` | Where the daemon is reachable, e.g. `<harp>:8780` or a Docker socket path |
| 6 | `nextcloud_url` | URL of this Nextcloud as reachable **from the ExApps**; it becomes each ExApp's `NEXTCLOUD_URL`. On a co-located single host it equals HaRP's `NC_INSTANCE_URL`; in split topologies (K8s, remote host) it may differ from what HaRP uses |

Options:

| Option | Meaning |
|---|---|
| `--net` | Docker network name (default `host`; `bridge` for `--k8s` daemons) |
| `--haproxy_password` | Basic-auth password for a Docker Socket Proxy daemon (DSP only) |
| `--compute_device` | `cpu`, `cuda`, or `rocm` |
| `--set-default` | Store as the default daemon (app config key `default_daemon_config`) |
| `--harp` | Use HaRP for all Docker + ExApp communication |
| `--harp_frp_address` | `host:port` of the HaRP FRP server (the FRP port is typically `8782`); required for HaRP unless `--k8s` |
| `--harp_shared_key` | HaRP shared key; must equal HaRP's `HP_SHARED_KEY` |
| `--harp_docker_socket_port` | FRP remote port selecting the Docker Engine (default `24000` = HaRP's local engine; remote engines use `24001-24099`, see `docs/appapi/remote-daemon.md`) |
| `--harp_exapp_direct` | Advanced: disable the FRP tunnel between ExApps and HaRP (see note below) |
| `--k8s` (NC34+) | Mark as Kubernetes daemon (requires `--harp`; forces `kubernetes-install`) |
| `--k8s_expose_type` (NC34+) | `nodeport`, `clusterip` (default), `loadbalancer`, or `manual` |
| `--k8s_node_port` (NC34+) | NodePort `30000-32767` (nodeport type only) |
| `--k8s_upstream_host` (NC34+) | Override upstream host for HaRP-to-ExApp (required for `manual` expose type) |
| `--k8s_external_traffic_policy` (NC34+) | `Cluster` or `Local` |
| `--k8s_load_balancer_ip` (NC34+) | LoadBalancer IP (loadbalancer type only) |
| `--k8s_node_address_type` (NC34+) | `InternalIP` (default) or `ExternalIP` |

Rules the command enforces:

- `--harp` requires `--harp_shared_key`, and requires `--harp_frp_address` unless `--k8s` is set.
- `--k8s` requires `--harp` and forces `accepts-deploy-id` to `kubernetes-install`.
- Registering a plain `docker-install` daemon without `--harp` (DSP) prints a deprecation/removal warning on
  NC34+ (see [Version notes](#11-version-notes-nc33--34--35)).
- `--harp_exapp_direct` drops the reverse FRP tunnel, so the ExApp must be directly network-reachable by HaRP;
  `net=host` is disallowed in this mode. Only use it inside a trusted network segment.

Kubernetes daemons (NC34+) use `kubernetes-install` with `http` against HaRP's `:8780` and need no
`--harp_frp_address`; they are occ-only (the admin UI shows them read-only). Full setup and the register
command (HaRP `HP_K8S_*` config, RBAC, expose types): [`docs/appapi/kubernetes.md`](docs/appapi/kubernetes.md).

Verify with `occ app_api:daemon:list` and the admin UI **Check connection** / **Test deploy**.

## 6. ExApp lifecycle (occ)

Typical flow: **register (install) --> enable --> [use] --> disable --> unregister**; `update` in place.
Source: `lib/Command/ExApp/`. `appid` is the ExApp's id.

| Command | Args and key options |
|---|---|
| `app_api:app:register <appid> [daemon]` | Install an ExApp. Omit `[daemon]` to use the default. Definition source: App Store (default), or `--info-xml <url-or-abs-path>`, or `--json-info <json>`. `--env NAME=VALUE` (repeatable) sets container env; `--mount SRC:DST[:ro\|rw]` (repeatable) adds mounts the app declares. `--wait-finish` blocks until deployed; `--silent`; `--test-deploy-mode` re-registers if already present. |
| `app_api:app:enable <appid>` | Enable a registered ExApp. No options. |
| `app_api:app:disable <appid>` | Disable a registered ExApp. No options. |
| `app_api:app:update [appid]` | Update one ExApp, or `--all` (with `--showonly` to preview, `--include-disabled` to widen). Reuses the ExApp's stored daemon and deploy options. |
| `app_api:app:unregister <appid>` | Remove an ExApp. `--rm-data` also deletes its persistent volume (data is **kept** by default). `--force` continues past errors; `--silent`. The Docker image is never removed automatically; prune it manually if disk space matters. |
| `app_api:app:list` | List ExApps: `<appid> (<name>): <version> [enabled\|disabled]`. No options. |

Notes:

- **App Store install** = `app_api:app:register <appid>` with no `--info-xml`/`--json-info`. **Manual/local
  install** = supply the definition via `--info-xml` or `--json-info`.
- If `[daemon]` is omitted, the command uses the `default_daemon_config` app-config value; if no default is
  set it fails. Set one with `--set-default` at daemon registration.
- `--keep-data` (on unregister) and `--force-scopes` (on register/update) are **deprecated no-ops**; do not
  rely on them. Data is kept by default; use `--rm-data` to delete it.
- Unregister cleans up everything the ExApp registered (UI entries, AI providers, Talk bots, webhooks, occ
  commands) but deliberately **keeps its app config and per-user preferences**, so a reinstall picks up the
  previous settings. There is no purge flag for those.

## 7. Operating AppAPI

- **ExApp configuration**: `app_api:app:config:get|set|delete|list` inspect or modify an ExApp's stored
  key/value configuration.
- **Private/mirror Docker registries**: `app_api:daemon:registry:add|remove|list` map registries for a
  daemon so ExApp images can be pulled from somewhere other than the default
  (`registry:add <daemon> --registry-from <url> --registry-to <url>`).
- **Daemons**: `app_api:daemon:list` / `app_api:daemon:unregister` manage daemon configs; re-run
  `app_api:daemon:register ... --set-default` to change the default. Note that `daemon:register` is a no-op if
  a daemon with that `name` already exists (see [Troubleshooting](#10-troubleshooting-symptom-first)).
  Unregistering a daemon is **blocked while ExApps still use it**, and there is no command to move an installed
  ExApp between daemons: unregister the ExApp and reinstall it on the new daemon (its config survives, see the
  lifecycle notes). That is also the DSP-to-HaRP migration path.
- **Logs**: ExApp containers are prefixed `nc_app_` (`docker logs nc_app_<appid>`); the daemon logs live in
  the HaRP container; Nextcloud-side errors are in the Nextcloud log.
- **Health checks**: the shipped admin setup checks are `DaemonCheck` (daemon reachable, default set) and
  `HarpVersionCheck` (HaRP new enough); they are the built-in post-install verification. An in-flight change
  targeting NC35 adds ExApp-surfaced checks (`ExAppsErrorSetupCheck`, `ExAppsWarningSetupCheck`) that raise
  ExApp-reported errors and "not responding" warnings into the admin overview; these are not yet in a stable
  release. Background jobs such as `ExAppInitStatusCheckJob` and
  `ExAppSetupChecksRefreshJob` refresh ExApp init/health state.
- **Certificates**: Nextcloud's certificate store (`occ security:certificates`) is pushed into every ExApp
  container at **deploy time** (all daemon types), so ExApps trust the same CAs as Nextcloud, including
  self-signed setups. After importing a new CA, update or reinstall ExApps to propagate it. Daemon connections
  over `https` always verify TLS with that same store and there is no bypass flag; import a self-signed daemon
  certificate into the store first.
- **Maintenance mode** (NC35+): `occ app_api:*` commands keep working and the HaRP control routes (ExApp
  metadata, init progress/state, logging) stay available, while ExApp end-user traffic and the ExApp
  config/preference APIs are rejected until maintenance ends (blocked AppAPI routes return 503 with
  `Retry-After: 120`). On NC33/34, app_api is not loaded during maintenance at all, so ExApps and
  `occ app_api:*` are unavailable for the duration.

## 8. App store / fetcher

There are **no** occ commands for the App Store; it is code-only under `lib/Fetcher/`.

- Default store URL `https://apps.nextcloud.com/api/v1` (`AppAPIFetcher::APP_STORE_URL`).
- Override with the Nextcloud system value `appstoreurl` (the only "custom app store" mechanism); the store is
  disabled if `appstoreenabled` is false.
- ExApp catalog file: `appapi_apps.json` (`ExAppFetcher`); updates are computed by `getExAppsWithUpdates()`.

## 9. Runtime and the ExApp contract

```
Browser --> Nextcloud reverse proxy (/exapps/*) --> HaRP (:8780) --> FRP tunnel --> ExApp container
```

- Nextcloud-to-daemon calls send the `harp-shared-key` header; ExApp URLs are under `/exapps/app_api/...`
  (`HarpService::initGuzzleClient()`; `getHarpSharedKey()` decrypts the key stored, encrypted, in
  `deploy_config['haproxy_password']`).
- A daemon is HaRP when `deploy_config['harp']` is set (`HarpService::isHarp()`), independent of
  `accepts_deploy_id`.
- Direct-connect mode (`--harp_exapp_direct`) drops the ExApp-to-HaRP FRP tunnel; note `net=host` is
  disallowed with HaRP direct mode.
- Some simpler UI integrations instead proxy through Nextcloud's own PHP route
  (`/index.php/apps/app_api/proxy/...`, `ExAppProxyController`), so a trivial ExApp may work even without the
  Step 4 `/exapps/` rule; WebSocket/streaming apps do not.

### The ExApp contract

AppAPI injects the same environment into every ExApp container (Docker and Kubernetes,
`DockerActions`/`KubernetesActions`):

| Env var | Meaning |
|---|---|
| `APP_ID`, `APP_VERSION`, `APP_DISPLAY_NAME` | Identity, from the ExApp's `info.xml` |
| `APP_SECRET` | Per-install shared secret for ExApp-to-Nextcloud authentication |
| `APP_HOST`, `APP_PORT` | Where the ExApp backend must listen |
| `APP_PERSISTENT_STORAGE` | Path of the persistent data volume |
| `NEXTCLOUD_URL` | The daemon's `nextcloud_url` positional |
| `COMPUTE_DEVICE` | `cpu`/`cuda`/`rocm` (plus `NVIDIA_*` vars for cuda) |
| `HP_FRP_ADDRESS`, `HP_FRP_PORT`, `HP_SHARED_KEY` | FRP tunnel wiring (HaRP daemons) |
| `AA_VERSION` | AppAPI version |

Lifecycle and authentication:

- After deploy, AppAPI waits for the ExApp's `/heartbeat` to respond, calls `/init`, and the ExApp reports
  init progress (0-100) back through the OCS status endpoint; at 100 it gets enabled. "Stuck initializing"
  means this loop stalled (see [Troubleshooting](#10-troubleshooting-symptom-first)).
- ExApp calls to Nextcloud carry the `EX-APP-ID`, `EX-APP-VERSION` and `AUTHORIZATION-APP-API` (base64
  `userid:APP_SECRET`) headers, validated by `AppAPIAuthMiddleware`. This is a **different credential** than
  the daemon's `harp-shared-key`: a 401 on an ExApp API call points at the app secret/headers, not the daemon
  key.
- Through these APIs an ExApp can register UI elements (top-menu entries, Files actions, scripts/styles),
  Task Processing (AI) providers, Talk bots, declarative settings, webhook listeners, and its own `occ`
  commands. All of these are cleaned up when the ExApp is unregistered.

## 10. Troubleshooting (symptom-first)

- **"AppAPI default deploy daemon is not set"**: no default daemon. Register one with `--set-default`
  ([Quickstart](#2-quickstart-zero-to-a-working-exapp)), or disable `app_api` if you do not use ExApps.
- **Daemon "Check connection" / `DaemonCheck` fails**: confirm `protocol`/`host` are reachable from the
  Nextcloud container; for HaRP confirm `--harp_shared_key` equals HaRP's `HP_SHARED_KEY`; confirm HaRP is
  running (`docker ps`) and on the same network as Nextcloud.
- **Shared-key errors / 401 from HaRP**: the `--harp_shared_key` and `HP_SHARED_KEY` differ, or the key has
  non-ASCII characters. Re-register the daemon with the exact key.
- **Fixed the key/host but nothing changed?** `app_api:daemon:register` is a no-op when a daemon with that
  `name` already exists (it prints "Registration skipped ..." and exits 0, so `--set-default` is skipped too).
  Run `occ app_api:daemon:unregister <name>` first, then re-register.
- **ExApp installed and `[enabled]`, but its page is blank / WebSocket fails**: the `/exapps/` reverse-proxy
  rule (Quickstart Step 4) is missing. The daemon checks pass without it because they use the internal path.
- **ExApp will not deploy**: image pull failing (registry/network). Check `docker ps -a` for `nc_app_*`, the
  ExApp container logs, and HaRP logs; a private registry needs `app_api:daemon:registry:add`.
- **HaRP not routing / 502**: wrong `--harp_frp_address`/port (default `8782` not reachable), or the reverse
  proxy is not forwarding `/exapps/` to HaRP `:8780`, or `net=host` was combined with HaRP direct mode. Check
  HaRP container logs.
- **ExApp unhealthy / stuck initializing**: `ExAppInitStatusCheckJob` tracks init state. Check the ExApp
  container logs and confirm `nextcloud_url` (and HaRP's `NC_INSTANCE_URL`) is reachable from the ExApp side.
- **`HarpVersionCheck` warns**: the HaRP container is older than the minimum supported version; update the
  HaRP image (`:release`).
- **GPU ExApp not using the GPU**: register the daemon with `--compute_device cuda|rocm`.
- **DSP deprecation warnings**: expected on NC34+; migrate the daemon to HaRP (`--harp`).

## 11. Version notes (NC33 / 34 / 35)

`main` is the NC35 dev line; released majors are `stableXX` branches. There is no runtime Nextcloud-version
gating in the deploy/registration code, so these differences are which code shipped in which major.

| Capability | NC33 | NC34 | NC35 (dev) |
|---|---|---|---|
| HaRP daemon | yes | yes | yes |
| Legacy DSP (`docker-install`, no `--harp`) | yes, no deprecation warning | yes, deprecation warning | present, removal targeted |
| Kubernetes (`kubernetes-install`, `--k8s*`) | no | yes | yes |
| `daemon:register` options | 9 (Docker/HaRP options, no `--k8s*`) | 16 (adds `--k8s*`) | 16 |
| AIO auto-daemon | `docker_aio` + `harp_aio` (neither deprecated) | `docker_aio` deprecated, `harp_aio` | `harp_aio` (`docker_aio` deprecated) |
| Connection/HaRP setup checks | `DaemonCheck`, `HarpVersionCheck` | same | same |
| ExApp-surfaced setup checks | no | no | in flight, not yet released |
| AppAPI available during maintenance mode | no | no | yes (occ + HaRP control routes) |

- The `--harp_*` flags are stable across NC33-35. The `--k8s_*` flags and `KubernetesActions` arrived in NC34.
- `harp_aio` auto-registration exists from NC33; the NC34 change was deprecating `docker_aio` (removal
  targeted for NC35).
- A hard stop that forbids registering **new** DSP daemons is planned for NC35 but is not in released code as
  of this writing; treat DSP as deprecated everywhere and prefer HaRP.
- ExApp-surfaced setup checks (`ExAppsErrorSetupCheck`, `ExAppsWarningSetupCheck`) are in flight for NC35 and
  not yet in a stable release; only `DaemonCheck` and `HarpVersionCheck` ship today.
- On NC35, maintenance mode keeps `occ app_api:*` and the HaRP control routes available while ExApp user
  traffic is rejected (see [Operating AppAPI](#7-operating-appapi)); NC33/34 do not load app_api during
  maintenance at all.

## 12. Developing app_api (start here)

`app_api` is a standard Nextcloud app: PHP backend in `lib/`, Vue frontend in `src/` (two webpack bundles from
`src/adminSettings.js` and `src/filesplugin.js`, built into `js/`), app metadata in `appinfo/`. HTTP routes
are declared in `appinfo/routes.php` and served by controllers in `lib/Controller/`. Deploy backends implement
`IDeployActions` (`DockerActions`/`ManualActions`/`KubernetesActions`); "is this HaRP" is `deploy_config['harp']`,
not the deploy type. See [Key files](#13-key-files) for where things live.

### Run your changes on a local Nextcloud

To test this checkout manually (distinct from the Quickstart, which installs the released app):

- Place or symlink this repo into the server's apps directory (e.g. `custom_apps/app_api` or
  `apps-extra/app_api`).
- `occ app:enable app_api`.
- `npm run watch` rebuilds the frontend on change; reload Nextcloud to pick it up.

### Build, test, lint

Use the composer/npm scripts (these are what CI runs). All from the repo root.

```bash
# PHP backend
composer install
composer cs:check        # php-cs-fixer dry-run over ./lib
composer cs:fix          # auto-fix code style
composer psalm           # static analysis (psalm.phar)
composer test:unit       # PHPUnit (config: tests/php/phpunit.xml)
composer openapi         # regenerate the OpenAPI specs (see below)
composer lint            # php -l syntax check

# Vue frontend
npm ci
npm run watch            # dev build with watch (webpack)
npm run build            # production build; commit the resulting js/ assets
npm run lint             # eslint (src)
npm run stylelint
npm test                 # vitest (JS unit tests)
```

### CI gates (what must pass)

- `lint.yml`: `info.xml` XSD, `composer lint`, `composer cs:check`, `composer psalm`, `npm run lint`,
  `npm run stylelint` (aggregated as `Lint-OK`).
- `phpunit.yml`: `composer test:unit` on PHP 8.3 and 8.4.
- `js-test.yml`: vitest on `src/**` changes.
- `openapi.yml`: runs `composer openapi` and **fails if the committed `openapi*.json` (and, if applicable,
  `src/types/openapi/*.ts`) are stale**. Regenerate and commit them whenever you touch controllers/routes.
- `node.yml`: `npm run build` and **fails if compiled `js/` assets are not committed**.
- `reuse.yml`: every file needs SPDX licensing info, via a file header or a `REUSE.toml` annotation.
- `tests-deploy*.yml`: end-to-end daemon lifecycle across Docker / HaRP / DSP and the four K8s expose types;
  `tests.yml` runs nc_py_api integration (PgSQL/MySQL/APcu). CI targets the server `master` line.

### Contributing conventions

- **Sign off every commit (DCO)**: `git commit -s`. The sign-off name/email must match the commit author.
  Nextcloud requires this to merge.
- **Commit messages**: concise, one line. Reference issues in the PR description, not the commit subject.
- **Target branch** `main`; release fixes are backported to the relevant `stableXX`.
- **PHP floor 8.2**, so do not use 8.3+ only syntax in `lib/`. Frontend engines: Node `^22`, npm `^10`.
- Before pushing: `composer cs:fix && composer psalm && composer test:unit`; if you touched the frontend,
  `npm run lint && npm run build`; if you touched controllers/routes, `composer openapi`. Commit the
  regenerated `openapi*.json`, any `src/types/openapi/*.ts`, and `js/` assets.
- New files need an SPDX header (see the top of this file for the format).

## 13. Key files

| Area | File(s) |
|---|---|
| Daemon registration CLI | `lib/Command/Daemon/RegisterDaemon.php` |
| ExApp lifecycle CLI | `lib/Command/ExApp/` |
| HTTP routes | `appinfo/routes.php` |
| Controllers | `lib/Controller/` (e.g. `ExAppProxyController`, `HarpController`) |
| Deploy backends | `lib/DeployActions/{DockerActions,ManualActions,KubernetesActions}.php` |
| AIO auto-registration | `lib/DeployActions/AIODockerActions.php` |
| HaRP logic | `lib/Service/HarpService.php` |
| Daemon config service | `lib/Service/DaemonConfigService.php` |
| App Store fetchers | `lib/Fetcher/{AppAPIFetcher,ExAppFetcher,ExAppArchiveFetcher}.php` |
| Setup checks | `lib/SetupChecks/` (`DaemonCheck`, `HarpVersionCheck`) |
| Background jobs | `lib/BackgroundJob/` |
| DB migrations / repair steps | `lib/Migration/` (`VersionXXXXXXDateYYYYYYYY.php` schema classes + repair steps) |
| Frontend | `src/` (Vue), entries `src/adminSettings.js` + `src/filesplugin.js`, built into `js/` via `webpack.js` |
| App metadata / command + job registration | `appinfo/info.xml` |
| Generated API specs | `openapi.json`, `openapi-administration.json`, `openapi-full.json` |
| Detailed setup runbooks | `docs/appapi/` (`kubernetes.md`, `remote-daemon.md`, `aio.md`) |

## 14. Related links

- **nc_py_api** (build ExApps in Python): https://github.com/cloud-py-api/nc_py_api
- **HaRP**: https://github.com/nextcloud/HaRP
- **Docker Socket Proxy** (legacy): https://github.com/nextcloud/docker-socket-proxy
- **Admin docs**: https://docs.nextcloud.com/server/latest/admin_manual/exapps_management/
- **Developer docs**: https://docs.nextcloud.com/server/latest/developer_manual/exapp_development/

## 15. Keep this file portable and current

This file ships in a public repo and is read by other people's AI assistants. Keep it true for **any**
deployment:

- **No secrets** and no real shared keys: use `$HP_SHARED_KEY` or `<PLACEHOLDERS>`.
- **No environment-specific values**: container names, hostnames, ports published only in one setup, or
  compose/VM paths belong in your own local notes, not here. (For example, a dev box may publish only FRP
  `8782` and reach `8780` by container name; a portable setup publishes both.)
- **Prefer verifiable facts**: cite the file/class when it helps, and prefer "how to verify" over bare
  assertions.
- **Update in the same change**: when AppAPI behavior changes, update the affected section here in the same PR,
  and note the Nextcloud version if it is version-specific.
