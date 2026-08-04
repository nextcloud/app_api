<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Developing ExApps in any language

A detailed runbook (a spoke of [`../../AGENTS.md`](../../AGENTS.md)).

An External App (ExApp) is a separate service, usually a container, that Nextcloud manages through AppAPI. Nothing
about the contract is Python-specific: an ExApp is anything that speaks HTTP and implements the small lifecycle
described here. This runbook teaches that contract and the two development loops. It is written so an AI coding
agent can follow it end to end.

Prerequisite: a working environment from [dev-environment.md](dev-environment.md) (Nextcloud + AppAPI + a HaRP
daemon `local-harp` + a manual-install daemon `manual_dev`).

Reference implementation: [examples/minimal_exapp](../../examples/minimal_exapp/) in this repository is the whole
contract in one framework-free Python file. Read it first; to develop in Go, Rust, Node or anything else, port
that file. For Python, use [nc_py_api](https://github.com/cloud-py-api/nc_py_api), which implements all of this
for you.

Last verified against: Nextcloud master (35), AppAPI 35.0.0-dev.1, HaRP 0.4.3, on 2026-08-04.

## Mental model

- Your app is an HTTP service. AppAPI (the PHP app inside Nextcloud) manages its lifecycle: deploy, health,
  init, enable, update, remove, and brokers authentication in both directions.
- The deploy daemon runs it. With a HaRP docker-install daemon, HaRP pulls and manages the container and carries
  all runtime traffic; with a manual-install daemon, you run the process yourself and Nextcloud talks to it
  directly.
- Users reach it under `/exapps/<appid>/...` (HaRP daemons) or `/index.php/apps/app_api/proxy/<appid>/...`
  (manual daemons); your app calls Nextcloud's OCS APIs with its app credentials.

The runtime traffic diagram and the injected-environment table live in [AGENTS.md](../../AGENTS.md) section 9;
this page does not repeat them.

## The contract

### Where to listen

- Deployed through HaRP (`HP_SHARED_KEY` env present): listen on the unix socket `/tmp/exapp.sock`. The frpc
  process started by `start.sh` (see Packaging) tunnels HaRP traffic to that socket.
- Everywhere else (manual-install, direct-connect daemons): listen on TCP `APP_HOST:APP_PORT` from the
  environment.

`minimal_exapp/main.py` implements both in a dozen lines; nc_py_api does the same internally.

### Endpoints AppAPI calls on you

| Endpoint | When | You must |
|---|---|---|
| `GET /heartbeat` | polled during install/update/enable, 1s cadence, several minutes of patience | Return `200` with JSON `{"status": "ok"}`. No auth required; keep it dependency-free and fast. |
| `POST /init` | once, after heartbeat succeeds | Return `200` immediately and do long setup (model downloads etc.) in the background, reporting progress `0..100` via `PUT /ocs/v1.php/apps/app_api/ex-app/status` with body `{"progress": N, "error": ""}`. Reaching 100 enables the app. If you have no init work: do not implement the route at all; AppAPI treats `404`/`501` as "no init needed". A non-empty `error` marks the install failed. |
| `PUT /enabled?enabled=1|0` | on enable/disable | Validate auth, then return `{"error": ""}`. This is where you register or remove UI elements, providers, bots (see Capabilities). 60s timeout; a non-empty `error` aborts the state change. |

Everything else your app serves is your own API, subject to your declared routes.

### Requests from Nextcloud: what you validate

Every request from Nextcloud/AppAPI (except `/heartbeat`) carries at minimum:

- `EX-APP-ID`: must equal your `APP_ID`
- `EX-APP-VERSION`: your registered version
- `AUTHORIZATION-APP-API`: `base64(user_id + ":" + APP_SECRET)`; the user id is empty for system/CLI calls

Validate all three: reject unless `EX-APP-ID` matches and the secret half equals your `APP_SECRET`; the user half
is the acting Nextcloud user. Reference checks: `minimal_exapp/main.py` (`_auth_user`) and the CI-verified
`tests/exapp_integration/_test_app.py` in this repo.

Do not validate `AA-VERSION` strictly: on the HaRP path HAProxy rewrites it (observed value `32`) regardless of
the real AppAPI version.

Browser traffic to `/exapps/<appid>/<route>` is enforced by HaRP against your declared routes before it reaches
you, and arrives with a synthesized, valid `AUTHORIZATION-APP-API` (empty user id for anonymous visitors on
PUBLIC routes). Observed behavior: declared PUBLIC route passes unsigned, undeclared paths are `404`, the
lifecycle endpoints are unreachable from outside by design.

### Requests to Nextcloud: how you authenticate

Call any Nextcloud OCS API with these headers (no signing, no session):

```
EX-APP-ID: <appid>
EX-APP-VERSION: <version>            (must be non-empty or you get 401)
AUTHORIZATION-APP-API: base64(user_id + ":" + APP_SECRET)
OCS-APIRequest: true
```

The user half selects the acting user: empty means system context; a user id means you act as that user (logged
to the impersonation audit). Worked example: `minimal_exapp/main.py` (`nc_request()`, used for both init progress
and the `/nc-version` demo endpoint). The AppAPI-specific OCS surface (UI, occ commands, task processing and so
on) is listed under Capabilities below.

Sending a higher `EX-APP-VERSION` than registered auto-updates the stored version; empty is rejected.

### The manifest

`appinfo/info.xml` with an `<external-app>` element declares identity, the Docker image coordinates, routes
(URL regex + verb + access level: PUBLIC, USER or ADMIN), and the allow-list of environment variables. The
complete reference, including the `--json-info` JSON form and the mounts/env-var semantics, is
[exapp-contract.md](exapp-contract.md); `minimal_exapp/appinfo/info.xml` is a minimal working instance.

### Packaging for HaRP (docker-install)

A HaRP daemon reaches your container exclusively through an FRP tunnel that your container opens back to HaRP.
This is empirically strict: an otherwise identical image without the tunnel deploys fine and then fails the
install with "heartbeat check failed" after several minutes. Your image therefore must:

1. Include the `frpc` binary and HaRP's `start.sh` as entrypoint wrapper; follow "Adapting ExApps to use HaRP"
   in the [HaRP README](https://github.com/nextcloud/HaRP) (checksum-pinned download, or `apk add frp` on
   Alpine 3.21). `start.sh` writes the frpc config from the injected `HP_*` variables and execs your command.
2. Serve on the unix socket as described above.
3. (Recommended) define a Docker `HEALTHCHECK` with a short `--start-interval`: AppAPI waits for the container
   to become healthy during install, so a slow first probe directly delays every deploy (observed: 47s vs 29s
   install for the same app). A missing healthcheck is treated as healthy.

`minimal_exapp/Dockerfile` is the smallest correct example.

## Loop 1 (fast): manual-install

Your app runs as a normal process on your machine; iterate by restarting it. No image builds involved.

From the nextcloud-docker-dev checkout root:

```bash
make -C workspace/server/apps-extra/app_api/examples/minimal_exapp run              # terminal 1: keep running
make -C workspace/server/apps-extra/app_api/examples/minimal_exapp register-manual  # terminal 2
```

Note: `register-manual` starts with a best-effort unregister, so it replaces any existing installation of the
app, including a docker-installed one from Stage 7.

The mechanics behind the Makefile, for any language:

1. Start your app with the environment it would otherwise be injected with:
   `APP_ID=... APP_VERSION=... APP_SECRET=<32+ chars> APP_PORT=<port> NEXTCLOUD_URL=http://nextcloud.local`.
   The app must be listening BEFORE registration: registering always polls `/heartbeat` and calls `/init`
   (`--wait-finish` additionally blocks until init reports 100).
2. Register:
   ```bash
   ./scripts/occ.sh nextcloud -- app_api:app:register <appid> manual_dev --wait-finish \
       --json-info '{"id":"<appid>","name":"...","version":"1.0.0","secret":"<same secret>","port":<port>,"routes":[{"url":"^\\/echo$","verb":"GET","access_level":0}]}'
   ```
   In the JSON form values are typed (`access_level`: 0=PUBLIC, 1=USER, 2=ADMIN). `host`/`protocol` keys are
   ignored; the daemon provides them (`manual_dev` points at `host.docker.internal`), the JSON provides the port.
3. Iterate: edit code, restart the process (stop it by its exact PID or Ctrl-C, then start it again).
   Re-register only when the manifest changes (routes, version).
4. Browser/API access for manual apps goes through the PHP proxy:
   `http://nextcloud.local/index.php/apps/app_api/proxy/<appid>/<path>` (the `/exapps/` path is served by HaRP
   and returns 404 for apps on plain manual daemons).
5. Clean up: `app_api:app:unregister <appid>`.

## Loop 2 (production-like): docker-install through HaRP

Verifies the real deployment: your image, frpc, routes, the works.

The one non-obvious piece is deploying an image that exists only locally. AppAPI normally always pulls, and a
failed registry pull aborts the deploy. The supported switch is a daemon registry mapping to `local`:

```bash
./scripts/occ.sh nextcloud -- app_api:daemon:registry:add local-harp --registry-from <registry> --registry-to local
```

where `<registry>` is exactly the `<registry>` string from your info.xml. With the mapping in place AppAPI skips
the pull and uses the local image named `<registry>/<image>:<image-tag>` (a `:<image-tag>-cpu` variant is probed
first). The mapping is daemon-wide, keyed by registry string: use a fictional registry for local development
(`minimal_exapp` uses `example.local`) so you never suppress pulls of real apps.

```bash
make -C workspace/server/apps-extra/app_api/examples/minimal_exapp build register-docker   # build + map + register, ~30s
```

### Redeploying after a change

Rebuilding an image does nothing by itself; a running container never picks up a new image, and neither does
`app:disable` + `app:enable`. The loops that work (all verified):

| Loop | Commands | Effect |
|---|---|---|
| Version bump (recommended) | bump `<version>` AND `<image-tag>` in info.xml (keep them equal; AppAPI deploys `<image-tag>`, `app:update` compares `<version>`); `make build update-docker` (= `app_api:app:update <appid> --info-xml ...` after rebuild) | Full redeploy from the new image; app secret, port, configuration and data volume all preserved. ~20s. |
| Re-register | `app_api:app:unregister <appid>` then `register ... --info-xml` | Same redeploy; secret is regenerated and port may change (pin both via `--json-info` if something external depends on them). Config and data volume survive. |
| One-shot | `app_api:app:register <appid> ... --test-deploy-mode --wait-finish` over the existing app | Implicit unregister + redeploy in one command; secret regenerates. |

`app:update` with an unchanged version is a hard no-op ("is already updated", container untouched), which is why
the version bump is required. For apps not in the app store, `--info-xml` (or `--json-info`) is mandatory on
update; without it AppAPI consults the app store and fails.

What survives what (verified): app configuration (`occ config:app:*` values) and user preferences survive
unregister and reinstall; the data volume `nc_app_<appid>_data` survives everything except `--rm-data`; the
Docker image is never removed by AppAPI.

## Capabilities: doing real things

Once enabled, an ExApp integrates through AppAPI's OCS APIs. Instead of duplicating those references, this is
the map of where to read, with the reference implementation next to each:

| You want | Read | nc_py_api reference |
|---|---|---|
| Files menu entries, top menu pages, UI scripts/styles | tech_details/api UI pages of the [ExApp developer docs](https://docs.nextcloud.com/server/latest/developer_manual/exapp_development/); live example: `tests/exapp_integration/test_file_actions_menu.py` (AppAPI API v2 over `ocs/v1.php`: `POST .../api/v2/ui/files-actions-menu`) | `nc_py_api/ex_app/ui/` |
| AI Task Processing providers (text, image, ...) | TaskProcessing section of the developer docs; a real provider: [llm2](https://github.com/nextcloud/llm2) | `nc_py_api/ex_app/providers/task_processing.py` |
| occ commands, event listeners, webhooks, notifications, Talk bots | tech_details/api pages | `nc_py_api/ex_app/` per-topic modules |
| Any Nextcloud core API (files, users, shares...) | the server's OCS/OpenAPI documentation (the `openapi*.json` files in this repo describe AppAPI's own endpoints only) | `nc_py_api/` client modules |
| App settings/config storage | AGENTS.md section 6 notes (config survives reinstall) | `nc_py_api.appconfig_ex` equivalents |

How nc_py_api itself does things is the fastest source of truth for any-language work: auth is
`nc_py_api/_session.py` (`sign_check`), the socket/TCP switch is `nc_py_api/ex_app/uvicorn_fastapi.py`, the
lifecycle handlers are `nc_py_api/ex_app/integration_fastapi.py`.

## Troubleshooting the development loops (symptom first)

| Symptom | Cause and fix |
|---|---|
| Install fails: "heartbeat check failed", app left `[disabled]` with container running | No frpc tunnel (image lacks start.sh/frpc), app listening on TCP instead of the unix socket in HaRP mode, or shared-key mismatch. `docker logs nc_app_<appid>`: you want "login to server success ... start proxy success" from frpc, then your own listen line. |
| Deploy aborts: `POST .../images/create ... 500` | Image only exists locally and no `registry ... to local` mapping on the daemon. |
| Deploy aborts instantly: "Connection refused for URI https://..." | Daemon registered `https` against HaRP's http port; re-register the daemon with `http`. |
| My rebuilt image is not what runs | You did not bump the version (`app:update` no-ops on same version), or no registry-to-local mapping so the registry copy was pulled. |
| ExApp gets 401 calling OCS | Missing/empty `EX-APP-VERSION`, wrong secret (did you restart a manual app with a different `APP_SECRET` than registered?), or the app is disabled and the path is not exempt. |
| `/exapps/<appid>/<path>` 404 | Route not declared in the manifest (or app on a manual daemon: use the PHP proxy path). |
| Register hangs forever | The app was not running/reachable before `--wait-finish` (manual-install), or `/init` never reports 100 and no error: check your status PUTs; the init watchdog only times out after ~40 minutes. |
| `PUT /ex-app/status` returns 401 while initializing | Only allowed while install/update is in progress or app enabled; report init progress promptly. |

More: [dev-environment.md](dev-environment.md) troubleshooting (environment-level) and
[AGENTS.md troubleshooting](../../AGENTS.md#10-troubleshooting-symptom-first) (operations-level).

## Related

- [dev-environment.md](dev-environment.md): the environment this page assumes.
- [exapp-contract.md](exapp-contract.md): manifest reference.
- [exapp-ai-maintenance.md](exapp-ai-maintenance.md): fix or extend an existing ExApp (this redeploy runbook
  applied to real apps).
- [AGENTS.md](../../AGENTS.md): [daemon flags](../../AGENTS.md#5-occ-app_apidaemonregister-reference),
  [lifecycle commands](../../AGENTS.md#6-exapp-lifecycle-occ),
  [runtime contract](../../AGENTS.md#9-runtime-and-the-exapp-contract),
  [operations troubleshooting](../../AGENTS.md#10-troubleshooting-symptom-first).
- HaRP README: FRP details, env vars, adapting images.
