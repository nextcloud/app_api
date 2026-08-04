<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Set up an ExApp development environment

A detailed runbook (a spoke of [`../../AGENTS.md`](../../AGENTS.md)).

This runbook builds a local Nextcloud development environment for creating and debugging ExApps, based on
[nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev): Nextcloud from source, AppAPI, a HaRP
deploy daemon, and a fast manual-install loop. It is written so that an AI coding agent can execute it end to end;
humans are welcome too.

Scope: local development only. Installing AppAPI on a production or existing instance is covered by the
Quickstart in [AGENTS.md](../../AGENTS.md) (section 2). Kubernetes, remote hosts and AIO have their own runbooks:
[kubernetes.md](kubernetes.md), [remote-daemon.md](remote-daemon.md), [aio.md](aio.md).

Last verified against: Nextcloud master (35), AppAPI 35.0.0-dev.1, HaRP 0.4.3, nextcloud-docker-dev df4ca69, on 2026-08-04.

## If you are an AI agent, read this first

- Execute stages in order; every stage ends with a Verify block. Do not continue past a failed Verify; use the
  matching "If it fails" entry, then re-verify.
- The environment is disposable, but its databases are not yours to destroy: never run `docker compose down -v`
  and never delete `workspace/` or named volumes without explicit human approval.
- Never edit tracked files of the nextcloud-docker-dev checkout for this setup. The whole AppAPI overlay lives in
  files that are untracked by design (`.env`, `docker-compose.override.yml`, `data/nginx/vhost.d/`).
- All state is discoverable by command (`docker compose ps`, `./scripts/occ.sh`, `docker logs`); verify state
  rather than assuming it.
- Kill development processes by exact PID only; on setups where ExApps run in Kubernetes their processes are
  visible on the host and a pattern kill can hit them.
- Use dev-only secrets in this environment and never reuse them elsewhere.

### Recommended sandbox

Run all of this inside a disposable Linux VM (or an equivalent isolated box) that the agent fully owns:

- Ubuntu 24.04 or any systemd Linux, 4+ vCPUs, 8+ GB RAM, 40+ GB disk.
- Docker Engine + docker compose v2, git, curl, make, sudo.
- The agent runs inside the sandbox with permission prompts relaxed; the Docker socket equals root, which is
  exactly why it should never be an agent's main machine. Snapshots make mistakes cheap.

Everything below assumes a shell inside that sandbox.

## Stage 1: clone and bootstrap

Goal: nextcloud-docker-dev checked out, `.env` generated, Nextcloud server source and app_api cloned.

```bash
git clone https://github.com/nextcloud/nextcloud-docker-dev
cd nextcloud-docker-dev
./bootstrap.sh app_api
```

Notes:
- `bootstrap.sh` writes `.env` (project name `master`, domain suffix `.local`, `PROTOCOL=http`, mysql), appends
  the `*.local` hostnames to `/etc/hosts` (needs sudo; a dnsmasq wildcard `address=/.local/127.0.0.1` is the
  alternative), clones `nextcloud/server` (shallow) into `workspace/server` plus a default app set, and because of
  the `app_api` argument also clones `nextcloud/app_api` into `workspace/server/apps-extra/app_api` and adds it to
  `NEXTCLOUD_AUTOINSTALL_APPS`.
- First run only: if `.env` already exists, `bootstrap.sh` validates and exits; arguments do nothing. On an
  existing checkout instead run:
  `git clone https://github.com/nextcloud/app_api workspace/server/apps-extra/app_api` and enable it in Stage 3.
- app_api needs no build step for runtime use (built JS is committed; composer/npm are only for developing app_api
  itself, see AGENTS.md section 12).

Verify:

```bash
grep -E "COMPOSE_PROJECT_NAME|DOMAIN_SUFFIX|PROTOCOL" .env
test -d workspace/server/apps-extra/app_api && echo app_api-cloned
getent hosts nextcloud.local
```

Expected: `COMPOSE_PROJECT_NAME=master`, `DOMAIN_SUFFIX=.local`, `PROTOCOL=http`; `app_api-cloned`;
`nextcloud.local` resolving to localhost (getent may print the `::1` row, the `127.0.0.1` row, or both).

If it fails:
- `nextcloud.local` does not resolve: re-run `./scripts/update-hosts` with sudo, or set up the dnsmasq wildcard.
- Missing `PROTOCOL=http` (hand-written `.env`): add it, otherwise Nextcloud generates https URLs and plain-http
  flows chase redirects.

## Stage 2: start Nextcloud

Goal: the master instance installed and answering on http://nextcloud.local with admin/admin.

```bash
docker compose up -d nextcloud
```

First start pulls images and auto-installs Nextcloud (admin/admin, plus users like user1/user1, jane/jane); a
static "installing" placeholder page is served meanwhile. Allow a few minutes on first run.

Verify (poll until installed):

```bash
for i in $(seq 1 60); do
    curl -sf http://nextcloud.local/status.php | grep -q '"installed":true' && break
    [ "$i" = 60 ] && { echo "Nextcloud did not come up within 5 minutes"; exit 1; }
    sleep 5
done
./scripts/occ.sh nextcloud -- status
```

Expected: `status.php` JSON with `"installed":true`; `occ status` shows the master version.

If it fails:
- Placeholder page persists past ~5 minutes: `docker compose logs nextcloud database-mysql` and look for the DB
  wait loop or install errors.
- 502/503: `docker compose ps`; confirm `proxy` and `nextcloud` are both up.

## Stage 3: enable AppAPI

```bash
./scripts/occ.sh nextcloud -- app:enable app_api
```

(No-op if autoinstall already enabled it via the bootstrap argument.)

Verify:

```bash
./scripts/occ.sh nextcloud -- app_api:daemon:list
```

Expected: the command runs (empty daemon list is fine at this point).

## Stage 4: add HaRP

Goal: the HaRP deploy-daemon container running next to Nextcloud.

First pick a dev-only shared key: any ASCII string; `<YOUR_DEV_KEY>` below stands for it everywhere (Stage 4 and
Stage 6 must use the byte-identical value; a mismatch is the single most common install failure).

Create `docker-compose.override.yml` in the repository root (docker compose loads it automatically; it stays
untracked, optionally list it in `.git/info/exclude`):

```yaml
services:
  appapi-harp:
    image: ghcr.io/nextcloud/nextcloud-appapi-harp:release
    restart: unless-stopped
    environment:
      HP_SHARED_KEY: "<YOUR_DEV_KEY>"
      NC_INSTANCE_URL: "http://nextcloud.local"
      HP_TRUSTED_PROXY_IPS: "192.168.21.0/24"   # DOCKER_SUBNET from .env
      HP_LOG_LEVEL: "info"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - harp-certs:/certs

volumes:
  harp-certs:
```

```bash
docker compose up -d appapi-harp
```

Notes:
- No `container_name`: compose names it `master-appapi-harp-1`, and the service name still provides the
  `appapi-harp` DNS alias on the compose network, which is what every later stage uses. A fixed name only
  invites collisions with older HaRP containers.
- FRP TLS stays on (the default): HaRP generates its certificates itself and AppAPI installs them into ExApp
  containers at deploy time; nothing to configure.
- The full `HP_*` reference lives in the HaRP README (Environment Variables section); nothing else needs tuning
  for a standard dev setup.

Verify:

```bash
docker compose ps appapi-harp
docker compose exec nextcloud curl -s -H "harp-shared-key: <YOUR_DEV_KEY>" http://appapi-harp:8780/exapps/app_api/info
```

Expected: status `Up ... (healthy)` and a JSON body containing `"docker": true` (the `version` field shows the
HaRP version, e.g. `"0.4.3"`). Plain `GET /` on port 8780 returns 404 by design; never use it as a health probe.

## Stage 5: route /exapps/ through the proxy

Goal: browser traffic for ExApps (`http://nextcloud.local/exapps/...`) reaches HaRP.

Create `data/nginx/vhost.d/nextcloud.local` (the file name must exactly equal the virtual host, so it changes
with `DOMAIN_SUFFIX`; this per-vhost snippet mechanism is the documented nginx-proxy customization path):

```nginx
location /exapps/ {
    proxy_pass http://appapi-harp:8780/exapps/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 1800s;
}
```

Then restart the proxy service. A plain `nginx -s reload` is NOT enough the first time: nginx-proxy only adds
the `include vhost.d/<host>` line to its generated config if the file existed when the config was generated, and
a reload re-reads but never regenerates.

```bash
docker compose restart proxy
```

Verify:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://nextcloud.local/exapps/app_api/info
```

Expected: `401` (HaRP is reachable through the proxy and rejects the unsigned request; that is the pass signal).

If it fails:
- `404` with an **Apache** error page: the request fell through to Nextcloud, meaning the location block is not
  active: the vhost file name does not match the virtual host, or the proxy was not restarted after the file was
  created (check with `docker compose exec proxy grep vhost.d /etc/nginx/conf.d/default.conf`).
- `404` with an **nginx** error page: the vhost itself is wrong (rare; check `VIRTUAL_HOST`/`DOMAIN_SUFFIX`).
- `502`: HaRP container down, or wrong service name in the snippet.

## Stage 6: register the deploy daemons

Goal: two daemons: HaRP (docker-install) for production-like deploys, and manual-install for the fast loop.

```bash
# HaRP daemon (docker-install); --net is this compose project's network
./scripts/occ.sh nextcloud -- app_api:daemon:register \
    local-harp "HaRP (local)" docker-install http appapi-harp:8780 http://nextcloud.local \
    --net master_default \
    --harp --harp_frp_address appapi-harp:8782 --harp_shared_key "<YOUR_DEV_KEY>" \
    --set-default

# Manual-install daemon (the ExApp process runs on your machine; its port comes from app registration)
./scripts/occ.sh nextcloud -- app_api:daemon:register \
    manual_dev "Manual (dev)" manual-install http host.docker.internal http://nextcloud.local
```

Notes:
- Protocol must be `http` for HaRP's port 8780 (8781 is the https frontend). Registering `https` against 8780
  fails later with "Connection refused for URI https://...".
- Re-registering an existing daemon name is a no-op; `app_api:daemon:unregister` first to change values.
- Full flag reference: AGENTS.md section 5.

Verify:

```bash
./scripts/occ.sh nextcloud -- app_api:daemon:list
```

Expected: both daemons listed, `local-harp` marked default.

## Stage 7: smoke test with the reference ExApp

Goal: prove the whole chain (AppAPI, HaRP, Docker, frpc, /exapps/ routing) with a real deploy before writing any
code. Uses [examples/minimal_exapp](../../examples/minimal_exapp/) from this repository.

From the nextcloud-docker-dev checkout root (the app_api clone from Stage 1 contains the example):

```bash
make -C workspace/server/apps-extra/app_api/examples/minimal_exapp build register-docker
```

The Makefile defaults match the daemon and container names above; its occ calls go through
`docker exec master-nextcloud-1`, so it works from any directory.

What this does: builds the image locally, maps its fictional registry `example.local` to `local` on the daemon
(so AppAPI skips the registry pull and uses your local image), registers the app, waits through heartbeat, init
(the app reports 25/50/75/100) and enable.

Verify:

```bash
./scripts/occ.sh nextcloud -- app_api:app:list
curl -s http://nextcloud.local/exapps/minimal_exapp/echo
curl -s http://nextcloud.local/exapps/minimal_exapp/nc-version
```

Expected: `minimal_exapp ... [enabled]`; `{"app_id": "minimal_exapp", ...}`; `{"nextcloud_version": "..."}` (the
second endpoint proves the ExApp can call Nextcloud OCS APIs).

This is the acceptance gate for the environment. Continue with
[exapp-development.md](exapp-development.md); clean up with
`make -C workspace/server/apps-extra/app_api/examples/minimal_exapp unregister` when done.

## Daily operation

- Start/stop: `docker compose up -d nextcloud appapi-harp` and `docker compose stop`. Never `down -v`.
- occ: `./scripts/occ.sh nextcloud -- <command>`; other instances: `./scripts/occ.sh stable34 -- <command>`.
- Logs: `docker compose logs -f nextcloud`; `docker compose logs -f appapi-harp`; ExApp logs:
  `docker logs -f nc_app_<appid>`.
- Update: `git -C workspace/server pull && git -C workspace/server submodule update --init 3rdparty`, pull
  apps-extra repos, `docker compose pull`, restart. Shallow-clone note: use `git fetch --depth=100` on shallow
  checkouts or a plain fetch downloads gigabytes of history.
- Stable-branch instances (test against NC34 etc.): prepare `workspace/stable34` per the upstream stable-versions
  doc, `docker compose up -d stable34`, then repeat Stages 3 to 6 against `stable34` with `stable34.local` names
  (including its own `data/nginx/vhost.d/stable34.local` snippet).

## Reset and recovery

| Situation | Action |
|---|---|
| Instance misbehaves after changes | `docker compose restart nextcloud`, then `./scripts/occ.sh nextcloud -- maintenance:repair` if needed |
| HaRP wedged | `docker compose restart appapi-harp` (safe; ExApp frpc clients retry and reconnect) |
| One service's config drifted | `docker compose up -d --force-recreate <service>` (containers are disposable; volumes hold state) |
| Failed ExApp install left a container behind | `./scripts/occ.sh nextcloud -- app_api:app:unregister <appid>` (add `--rm-data` to also drop its data volume) |
| Full reset (DESTROYS all instances' data) | Requires explicit human approval: `docker compose down -v`, then re-run from Stage 2 |

## Troubleshooting (symptom first)

| Symptom | Likely cause and fix |
|---|---|
| Placeholder "installing" page forever | DB not ready or install failed: `docker compose logs nextcloud database-mysql` |
| `nextcloud.local` does not resolve | `/etc/hosts` not updated (sudo) or dnsmasq wildcard missing |
| `daemon:register` cannot connect | Wrong `--net` (must be this project's network, default `master_default`), HaRP down, or host typo |
| Deploy fails instantly: "Connection refused for URI https://..." | Daemon registered with protocol `https` against HaRP's http port 8780; unregister and re-register with `http` |
| Deploy fails at pull: `.../images/create ... 500` | Image exists only locally and the daemon has no `registry ... to local` mapping; see exapp-development.md |
| Install hangs minutes, then "heartbeat check failed"; app left `[disabled]`, container running | Shared-key mismatch between daemon and `HP_SHARED_KEY` (failure number one), or the image lacks the frpc/start.sh HaRP adaptation; check `docker logs nc_app_<appid>` for "start proxy success" |
| `/exapps/` returns an Apache 404 page | Fell through to Nextcloud: vhost.d file name wrong, or the proxy was never restarted after the file was created (Stage 5) |
| `/exapps/<app>/<declared-route>` returns 404 | Route not declared in the app's manifest, or the app sits on a non-HaRP daemon (manual apps are served at `/index.php/apps/app_api/proxy/<appid>/...`) |
| `/exapps/` returns 502 | HaRP container down, or a browser request to an infrastructure route (`/heartbeat`, `/init`, `/enabled`), which is blocked by design |
| Ports 80/443 busy on the host | Another web server; stop it or change the bind/port settings in `.env` |

More production-shaped symptoms:
[AGENTS.md troubleshooting](../../AGENTS.md#10-troubleshooting-symptom-first).

## Related

- [AGENTS.md](../../AGENTS.md): [Quickstart (production install)](../../AGENTS.md#2-quickstart-zero-to-a-working-exapp),
  [`daemon:register` reference](../../AGENTS.md#5-occ-app_apidaemonregister-reference),
  [ExApp lifecycle](../../AGENTS.md#6-exapp-lifecycle-occ),
  [developing app_api itself](../../AGENTS.md#12-developing-app_api-start-here).
- [exapp-development.md](exapp-development.md): write and iterate on an ExApp in any language.
- [exapp-ai-maintenance.md](exapp-ai-maintenance.md): fix or extend an installed ExApp.
- nextcloud-docker-dev documentation: https://nextcloud.github.io/nextcloud-docker-dev/
- HaRP README (env vars, reverse-proxy examples, adapting ExApps): https://github.com/nextcloud/HaRP
