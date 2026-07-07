<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ExApps on a separate host (remote deploy daemon)

A detailed runbook (a spoke of [`../../AGENTS.md`](../../AGENTS.md)). Read this when ExApp containers should run
on a **different machine** than Nextcloud, for example a GPU server for AI ExApps. For the single-host golden
path, concepts, and command references, stay in `AGENTS.md`.

Placeholders in angle brackets are yours to fill; never commit a real secret.

## Contents

1. [Pick a topology](#pick-a-topology)
2. [Model A: HaRP near Nextcloud, remote Docker Engine over FRP](#model-a-harp-near-nextcloud-remote-docker-engine-over-frp)
3. [Model B: HaRP on the ExApp host](#model-b-harp-on-the-exapp-host)
4. [Verification](#verification)
5. [Troubleshooting](#troubleshooting)

## Pick a topology

Nextcloud never talks to a remote Docker Engine directly. Its Docker API calls go **through HaRP's HTTP
frontend**, with a `docker-engine-port` header selecting which engine: `24000` is HaRP's own local engine (the
mounted socket), and each additional engine connects over an FRP tunnel and gets a unique remote port in
`24001-24099` - that is what `--harp_docker_socket_port` selects (up to 99 engines per HaRP).

| Model | Where HaRP runs | ExApp engine | Choose when |
|---|---|---|---|
| **A** | Next to Nextcloud | Remote; its Docker socket tunnels back to HaRP over FRP (mutual TLS) | One central HaRP, one or many ExApp hosts; the remote host only needs **outbound** access to HaRP |
| **B** | On the ExApp host | Local to HaRP (mounted socket) | One remote ExApp host, and Nextcloud can reach its ports `8780`/`8782` directly |

Model A is the setup the HaRP README documents for external engines and scales to many hosts; Model B is
simply the single-host Quickstart placed on the remote machine.

In both models the ExApps run on the remote host and their FRP clients dial back to HaRP's `8782`, so the
`nextcloud_url` positional (and HaRP's `NC_INSTANCE_URL`) must be reachable **from the remote host**; never
`localhost`.

## Model A: HaRP near Nextcloud, remote Docker Engine over FRP

**Step 1. Run HaRP next to Nextcloud** exactly as in the `AGENTS.md` Quickstart (Steps 2-4: shared key,
`docker run`, `/exapps/` reverse-proxy rule). `--harp_frp_address` (and HaRP's FRP port `8782`) must be
reachable from the remote host, so use a real hostname/IP, not a Docker-internal name. If HaRP should manage
**only** remote engines, you may omit the `/var/run/docker.sock` mount.

**Step 2. Copy the FRP client certificates to the remote host.** HaRP generates mutual-TLS certs at
`/certs/frp` inside its container:

```bash
mkdir -p harp_frpc_docker/certs/frp && cd harp_frpc_docker
for f in client.crt client.key ca.crt; do docker cp appapi-harp:/certs/frp/$f certs/frp/; done
# transfer the harp_frpc_docker folder to the remote host
```

**Step 3. Create `frpc.toml` on the remote host** (in that folder):

```toml
serverAddr = "<harp-host>"                 # HaRP's address as reachable from this host
serverPort = 8782
loginFailExit = false

transport.tls.certFile = "certs/frp/client.crt"
transport.tls.keyFile = "certs/frp/client.key"
transport.tls.trustedCaFile = "certs/frp/ca.crt"
transport.tls.serverName = "harp.nc"       # do not change

metadatas.token = "<HP_SHARED_KEY value>"

[[proxies]]
remotePort = 24001                         # unique per engine, range 24001-24099
name = "deploy-daemon-1"                   # unique per engine
type = "tcp"
[proxies.plugin]
type = "unix_domain_socket"
unixPath = "/var/run/docker.sock"
```

**Step 4. Run the FRP client on the remote host:**

```bash
docker run -d --name harp_frpc_docker \
    --restart unless-stopped \
    -v "$(pwd)/frpc.toml:/etc/frpc.toml" \
    -v "$(pwd)/certs:/certs" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    ghcr.io/fatedier/frpc:v0.61.1 "-c=/etc/frpc.toml"
```

**Step 5. Register the daemon** on Nextcloud. `host` stays HaRP's frontend; the remote engine is selected by
`--harp_docker_socket_port`. `--net` names a Docker network **on the remote engine** (`bridge` unless you
created one there):

```bash
occ app_api:daemon:register \
    remote1 "Remote GPU host" docker-install http <harp-host>:8780 <nextcloud-url-reachable-from-remote-host> \
    --net bridge \
    --harp \
    --harp_frp_address <harp-host>:8782 \
    --harp_shared_key "$HP_SHARED_KEY" \
    --harp_docker_socket_port 24001 \
    --set-default
```

Add `--compute_device cuda|rocm` if the remote host has a GPU.

> **Certificate lifetime.** The FRP certs are valid for `HP_FRP_CERT_VALIDITY_DAYS` days (default `5000`) and
> are **not renewed automatically**; an expired cert silently kills the tunnel. To renew: stop HaRP, delete its
> `/certs/frp` folder, start HaRP, re-copy the three files to every remote engine and restart each `frpc`.
> ExApps embed these certs at **install** time, so after regenerating you must remove and re-install each
> ExApp; a restart is not enough.

## Model B: HaRP on the ExApp host

Run HaRP on the remote machine exactly as in the `AGENTS.md` Quickstart Step 3 (it mounts that host's
`/var/run/docker.sock`; publish `8780` and `8782`), then register it from Nextcloud by address:

```bash
occ app_api:daemon:register \
    remote1 "HaRP (ExApp host)" docker-install http <remote-host>:8780 <nextcloud-url-reachable-from-remote-host> \
    --net <remote-docker-network> \
    --harp \
    --harp_frp_address <remote-host>:8782 \
    --harp_shared_key "$HP_SHARED_KEY" \
    --set-default
```

`--harp_docker_socket_port` stays at its default `24000` (HaRP's local engine). Your Nextcloud reverse proxy
must forward `/exapps/` to `http://<remote-host>:8780` (Quickstart Step 4), and Nextcloud must be able to
reach `8780` on the remote host; consider TLS (`https` + `8781` with certs) or a private network for that leg,
since ExApp traffic crosses hosts.

## Verification

```bash
occ app_api:daemon:list           # shows the daemon with its frp_address and docker_socket_port
```

- Admin UI **Check connection** and **Test deploy** (Test deploy pulls and runs a real test container on the
  remote engine, so it validates the tunnel + registry path end to end).
- Model A tunnel check, on the HaRP host: `docker exec appapi-harp curl -fsS http://127.0.0.1:24001/_ping`
  (expects `OK` from the remote Docker Engine; use your `remotePort`).
- Install an ExApp, then on the **remote** host: `docker ps --filter name=nc_app_`.

## Troubleshooting

- **Check connection fails / tunnel dead (Model A)**: the remote `frpc` cannot reach `<harp-host>:8782`
  (firewall/outbound), the certs were not copied, or the `metadatas.token` does not equal `HP_SHARED_KEY`.
  Check the `frpc` container logs on the remote host and HaRP's logs.
- **`_ping` on the remote port fails but frpc is connected**: wrong `remotePort` in `frpc.toml` vs
  `--harp_docker_socket_port`, or two engines claimed the same port.
- **Deploy works but the ExApp stays unhealthy**: `nextcloud_url`/`NC_INSTANCE_URL` is not reachable from the
  remote host (DNS/firewall), or the ExApp's FRP client cannot reach `<harp-host>:8782`.
- **Everything worked for years, then all remote deploys break at once**: FRP cert expiry; see the certificate
  lifetime note above.
- **Re-registering after a fix changes nothing**: `daemon:register` is a no-op for an existing name;
  `daemon:unregister` first (see `AGENTS.md` Troubleshooting).

For daemon flags and general lifecycle, see [`../../AGENTS.md`](../../AGENTS.md).
