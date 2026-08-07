<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Fix or extend an installed ExApp (with an AI agent)

A detailed runbook (a spoke of [`../../AGENTS.md`](../../AGENTS.md)).

ExApps are open source and run from Docker images, which means any installed ExApp can be changed locally: when
something is broken or missing, you (or the AI coding agent working for you) can get the source, make the change,
rebuild the image, and make your Nextcloud run the rebuilt one, without waiting for a release. This runbook is
that loop. It is written for an AI agent executing it on a user's behalf; the user's part is one sentence, like
"add EuroLLM support to llm2" or "llm2 never reports progress, fix it".

Works against any docker-install HaRP daemon you control, including the development environment from
[dev-environment.md](dev-environment.md). Not covered: Kubernetes daemons (their images come from an in-cluster
registry; the local-image switch below is Docker-only) and manual-install apps (just edit and restart the
process; see [exapp-development.md](exapp-development.md)).

Last verified against: Nextcloud master (35), AppAPI 35.0.0-dev.1, HaRP 0.4.3, on 2026-08-04.

## Ground rules for the agent

- Never use `--rm-data`; the app's data volume and configuration are the user's.
- Prefer the smallest change that solves the stated problem; follow the upstream project's conventions.
- Registry mappings are daemon-wide: adding `--registry-from ghcr.io --registry-to local` stops image pulls for
  EVERY app from that registry on that daemon. Acceptable on a dev daemon; on a shared instance, remove the
  mapping right after the update (step 6) or plan deliberately.
- The user sees results, not diffs: finish with the verification step and offer to submit the change upstream.

## The runbook

### 1. Identify what is running

```bash
occ app_api:app:list                 # appid, version, enabled state
occ app_api:daemon:list              # which daemon; must be a docker-install HaRP daemon for this runbook
docker ps --filter name=nc_app_<appid>
```

### 2. Get the matching source

The app store page and `<repository>` in the app's info.xml point at the source repository. Clone it and check
out the tag matching the INSTALLED version (fix what is running, not the moving main branch):

```bash
git clone <repo> && cd <repo> && git checkout v<installed-version>    # tag scheme varies by project
```

### 3. Diagnose or design

- Runtime evidence: `docker logs nc_app_<appid>`, the Nextcloud log, the app's own endpoints.
- For a missing capability, find where the equivalent existing capability lives and mirror it (worked example
  below: new model support = one list entry + one config block).

### 4. Make the change and bump the version

Edit the source, then bump `<version>` in `appinfo/info.xml`. Use a fourth version segment for local builds,
for example `2.8.0.1` on top of `2.8.0`: it sorts above the installed release but below upstream's next patch
(`2.8.1`), so a future store update still takes over cleanly. The bump is not cosmetic: `app_api:app:update`
compares versions and is a verified no-op when they are equal, so an unbumped rebuild never reaches the
container.

### 5. Rebuild the image under its original name

Build with the exact coordinates from info.xml (`<registry>/<image>:<image-tag>`), on the machine whose Docker
daemon the HaRP daemon uses:

```bash
docker build -f <Dockerfile variant> -t <registry>/<image>:<new-tag> .
```

(Also update `<image-tag>` in info.xml to the new tag; keeping it equal to `<version>` is the common convention.)

### 6. Point the daemon at local images and update

The `--info-xml` path is read by the Nextcloud PHP process, so on a containerized Nextcloud copy the file into
the container first:

```bash
occ app_api:daemon:registry:add <daemon> --registry-from <registry> --registry-to local
docker cp appinfo/info.xml <nextcloud-container>:/tmp/<appid>-info.xml
occ app_api:app:update <appid> --info-xml /tmp/<appid>-info.xml --wait-finish
occ app_api:daemon:registry:remove <daemon> --registry-from <registry> --registry-to local   # on shared daemons
```

Facts behind this (all verified live): the mapping makes AppAPI skip the registry pull and use the local image;
without it a local-only image aborts the deploy at pull. `--info-xml` is mandatory here because AppAPI would
otherwise consult the app store (and for the same reason, the admin UI update button would reinstall the stock
version). The update disables the app, recreates the container from the new image and re-enables it, preserving
the app secret, port, app configuration, user preferences and the data volume. Downloads the app performed into
its persistent storage (language models and the like) survive, so an update does not re-fetch them.

### 7. Verify

```bash
occ app_api:app:list                                      # new version, [enabled]
docker inspect nc_app_<appid> --format '{{.Config.Image}}'  # your tag
```

plus the functional check for the change itself (the worked example's is "the new model appears in the admin AI
settings and answers a task").

### 8. Aftermath

- Offer to upstream the change (issue + pull request); local rebuilds are for immediacy, upstream is the fix.
- To return to stock: remove the registry mapping (if still present) and run `occ app_api:app:update <appid>`
  without `--info-xml`; the app store definition and image take over again at the next store release. To pin the
  local build, keep the mapping and do nothing.

## Worked example: "Add EuroLLM support to llm2"

llm2 is Nextcloud's local large-language-model ExApp. Every model it loads becomes its own set of Task
Processing providers ("Local Large language Model: <model>"), so adding a model is user-visible in the admin AI
settings. The user asks: "I want the European EuroLLM model available in my Assistant."

The change (three files):

1. `lib/main.py`, `models_to_fetch`: one entry, commit-pinned like its neighbors:
   ```python
   "https://huggingface.co/bartowski/EuroLLM-9B-Instruct-GGUF/resolve/<revision>/EuroLLM-9B-Instruct-Q4_K_M.gguf":
       {"save_path": os.path.join(persistent_storage(), "EuroLLM-9B-Instruct-Q4_K_M.gguf")},
   ```
2. `default_config/config.json`: a first-class config entry keyed by the GGUF file name: context size and
   template facts from the model card (EuroLLM: ChatML template, stop `<|im_end|>`, `n_ctx` 4096,
   `max_tokens` 2048), mirroring the shape of the existing entries.
3. `appinfo/info.xml`: version and image-tag bump.

Then steps 5 to 7: `docker build -f Dockerfile.cpu -t ghcr.io/nextcloud/llm2:<new-tag> .`, registry mapping,
`app:update --info-xml`. During init llm2 downloads only the new 5.6 GB model (its existing models sit in the
persistent volume, which survives the update); when it re-registers its providers, "Local Large language Model:
EuroLLM-9B-Instruct-Q4_K_M" appears for every text task type, ready to be selected and asked for a German or
French summary.

Verified end to end: install including the 5.6 GB model download took under ten minutes; a later code-change
redeploy took ~20 seconds with the model volume untouched; the new providers appeared for every text task type
and answered a German prompt correctly. One real-world beat from that verification: the then-current CPU image
resolved Python 3.10 while the code used an API from 3.11, crashing the task loop silently (upstream issue
nextcloud/llm2#284); the agent noticed it in the logs and handled it inside the same loop, which is exactly what
this runbook is for.

A bugfix-flavored example with the same mechanics: llm2 does not report per-token generation progress
(upstream issue nextcloud/llm2#241); the fix is a small change in its streaming loop, and reaches the running
instance through exactly steps 4 to 7.

## Troubleshooting (symptom first)

| Symptom | Cause and fix |
|---|---|
| "ExApp <id> is already updated" and nothing changed | Version not bumped; `app:update` no-ops on equal versions. |
| "Failed to get app info for '<id>' from the Appstore" | `--info-xml`/`--json-info` missing on update of a non-store version. |
| Deploy aborts at pull (`.../images/create ... 500`) | Registry mapping to `local` missing, or image name/tag does not exactly match info.xml. |
| Update succeeded but behavior unchanged | Rebuilt image under a different name/tag than info.xml declares, or the mapping was absent so the registry copy was pulled; check `docker inspect nc_app_<id> --format '{{.Config.Image}}'` and `docker images`. |
| App store update button "reinstalled" the stock app | Expected: the UI updates from the store. Local builds are occ-driven (`--info-xml`). |
| App on a Kubernetes daemon | This runbook does not apply; build and push to a registry the cluster can pull, or move the app to a docker daemon for the debugging session. |

## Related

- [exapp-development.md](exapp-development.md): the loops in a development setting, and the full redeploy table.
- [dev-environment.md](dev-environment.md): a sandboxed environment to practice this in.
- [AGENTS.md](../../AGENTS.md): [lifecycle command semantics](../../AGENTS.md#6-exapp-lifecycle-occ) and
  [operations troubleshooting](../../AGENTS.md#10-troubleshooting-symptom-first).
