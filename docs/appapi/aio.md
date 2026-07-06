<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ExApps on Nextcloud AIO

A short runbook (a spoke of [`../../AGENTS.md`](../../AGENTS.md)). Read this when the Nextcloud instance is
**AIO (all-in-one)**. AIO manages the deploy daemon for you: most of the manual setup in the `AGENTS.md`
Quickstart does not apply, and fighting the managed daemon is the main failure mode.

## What AIO automates

AppAPI detects AIO via the `THIS_IS_AIO` env var and auto-registers the deploy daemon during app
install/upgrade (`lib/DeployActions/AIODockerActions.php`, `lib/Migration/DataInitializationStep.php`):

- With AIO's **HaRP container** enabled (`HARP_ENABLED` + `HP_SHARED_KEY` set by AIO), AppAPI registers
  **`harp_aio`** (`nextcloud-aio-harp:8780`, network `nextcloud-aio`, HaRP direct-connect mode) and makes it
  the default. Exists on NC33+.
- With the legacy **Docker Socket Proxy** container, AppAPI registers **`docker_aio`**
  (`nextcloud-aio-docker-socket-proxy:2375`). Deprecated since NC34, removal targeted for NC35.
- If both are enabled, `harp_aio` wins the default. Registration is idempotent and AppAPI never removes a
  daemon it registered: after a DSP-to-HaRP migration, `docker_aio` stays behind; clean it up with
  `occ app_api:daemon:unregister docker_aio` once no ExApps use it.
- AIO's own web server container routes `/exapps/` to HaRP internally, so the `AGENTS.md` Quickstart Step 4
  proxy rule is only needed on a reverse proxy **in front of** AIO, if you run one.

## What the admin does

1. In the AIO interface, enable the **HaRP community container** (or add `harp` to the
   `AIO_COMMUNITY_CONTAINERS` env var of the mastercontainer), then restart the AIO containers so the env
   reaches the Nextcloud container.
2. That is all for the daemon: check **Settings --> Administration --> AppAPI** shows the `AIO HaRP` daemon,
   and use **Check connection** / **Test deploy**.
3. Install ExApps normally (UI store, or `occ app_api:app:register <appid> --wait-finish`; on AIO run occ as
   `docker exec -u www-data nextcloud-aio-nextcloud php occ ...`).

## What NOT to do on AIO

- Do **not** run your own HaRP container or hand-register another HaRP daemon; `harp_aio` is AIO-managed
  (a second daemon competes with it, and re-registering the same name is a no-op).
- Do **not** edit `harp_aio`'s host or shared key by hand; AIO owns `HP_SHARED_KEY` and a mismatch breaks
  Nextcloud-to-HaRP auth.
- Do not re-default the deprecated `docker_aio`; migrate to HaRP and unregister it.

## Troubleshooting

- **"AppAPI default deploy daemon is not set"** on AIO: the HaRP community container is not enabled or not
  running, so `HARP_ENABLED`/`HP_SHARED_KEY` never reached the Nextcloud container and auto-registration
  skipped itself. Enable the container, restart AIO, and re-check.
- **HaRP enabled but no `harp_aio` daemon**: the Nextcloud container was not recreated after enabling HaRP,
  so the env vars are missing inside it. Restart/recreate the AIO containers; the (idempotent) registration
  re-runs on the next app_api install/upgrade repair step.
- **ExApp installs fine but its page is blank / WebSocket fails, only behind your own proxy**: the reverse
  proxy in front of AIO does not forward `/exapps/` (with WebSocket upgrade). The internal checks pass anyway;
  add the rule from `AGENTS.md` Quickstart Step 4 pointing at AIO.

For everything else (lifecycle, troubleshooting, version notes) see [`../../AGENTS.md`](../../AGENTS.md).
