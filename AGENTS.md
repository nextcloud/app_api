<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# AppAPI agent guide

This is the start point for working with **`app_api`**. The guidance has two halves, in two homes:

- **Operating AppAPI and building ExApps**: installing on a real Nextcloud, deploy daemons (Docker, Kubernetes,
  remote hosts, AIO), the ExApp lifecycle and troubleshooting, developing ExApps in any language, fixing or
  extending an installed ExApp, and a full local development environment. These guides are maintained in the
  dedicated skills repository for AI agents, **nextcloud-skills**:
  https://github.com/oleksandr-nc/nextcloud-skills. Start at its `AGENTS.md`; the content is plain markdown in
  the cross-vendor Agent Skills format and works for any agent that can read files. That repository is the
  canonical home for these guides.
- **Developing `app_api` itself** (this repository's PHP/Vue code): the rest of this file.

This repo's `main` is the NC35 dev line; released majors live on `stableXX` branches. This is a **living
document**; [Keep this file portable and current](#5-keep-this-file-portable-and-current) says where updates
go.

## Table of contents

1. [What AppAPI is](#1-what-appapi-is)
2. [Developing app_api](#2-developing-app_api)
3. [Key files](#3-key-files)
4. [Related links](#4-related-links)
5. [Keep this file portable and current](#5-keep-this-file-portable-and-current)

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

How to install, configure and operate all of this, and how to build the ExApp side: the
[nextcloud-skills](https://github.com/oleksandr-nc/nextcloud-skills) repository (see the top of this file).

## 2. Developing app_api

`app_api` is a standard Nextcloud app: PHP backend in `lib/`, Vue frontend in `src/` (two webpack bundles from
`src/adminSettings.js` and `src/filesplugin.js`, built into `js/`), app metadata in `appinfo/`. HTTP routes
are declared in `appinfo/routes.php` and served by controllers in `lib/Controller/`. Deploy backends implement
`IDeployActions` (`DockerActions`/`ManualActions`/`KubernetesActions`); "is this HaRP" is `deploy_config['harp']`,
not the deploy type. See [Key files](#3-key-files) for where things live.

### Run your changes on a local Nextcloud

To test this checkout manually (distinct from a normal install, which uses the released app):

- Place or symlink this repo into the server's apps directory (e.g. `custom_apps/app_api` or
  `apps-extra/app_api`).
- `occ app:enable app_api`.
- `npm run watch` rebuilds the frontend on change; reload Nextcloud to pick it up.

For a full local environment (nextcloud-docker-dev with HaRP and both development daemons), use the
`nextcloud-dev-setup` skill in the
[nextcloud-skills](https://github.com/oleksandr-nc/nextcloud-skills) repository.

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
- **PHP floor 8.3**, so do not use 8.4+ only syntax in `lib/`. Frontend engines: Node `^22`, npm `^10`.
- Before pushing: `composer cs:fix && composer psalm && composer test:unit`; if you touched the frontend,
  `npm run lint && npm run build`; if you touched controllers/routes, `composer openapi`. Commit the
  regenerated `openapi*.json`, any `src/types/openapi/*.ts`, and `js/` assets.
- New files need an SPDX header (see the top of this file for the format).

## 3. Key files

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

## 4. Related links

- **nextcloud-skills** (operating AppAPI + building ExApps, for AI agents and humans):
  https://github.com/oleksandr-nc/nextcloud-skills
- **nc_py_api** (build ExApps in Python): https://github.com/cloud-py-api/nc_py_api
- **nextcloud-docker-dev** (development environment): https://github.com/nextcloud/nextcloud-docker-dev
- **HaRP**: https://github.com/nextcloud/HaRP
- **Docker Socket Proxy** (legacy): https://github.com/nextcloud/docker-socket-proxy
- **Admin docs**: https://docs.nextcloud.com/server/latest/admin_manual/exapps_management/
- **Developer docs**: https://docs.nextcloud.com/server/latest/developer_manual/exapp_development/

## 5. Keep this file portable and current

This file ships in a public repo and is read by other people's AI assistants. Keep it true for **any**
deployment:

- **No secrets** and no real shared keys: use `<PLACEHOLDERS>`.
- **No environment-specific values**: container names, hostnames, ports published only in one setup, or
  compose/VM paths belong in your own local notes, not here.
- **Prefer verifiable facts**: cite the file/class when it helps, and prefer "how to verify" over bare
  assertions.
- **Update in the right home, in the same change**: when AppAPI's observable behavior changes, update the
  affected guide in the nextcloud-skills repository in the same change; update this file when the app_api
  development workflow (build, test, CI, layout) changes.
