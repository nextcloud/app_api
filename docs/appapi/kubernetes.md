<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# AppAPI on Kubernetes

A detailed runbook (a spoke of [`../../AGENTS.md`](../../AGENTS.md)). Read this when the deploy daemon is
Kubernetes; for the common Docker path, concepts, and the full command references, stay in `AGENTS.md`.
Kubernetes support is **NC34+**.

Placeholders in angle brackets are yours to fill; never paste a real secret or a real cluster address into a
committed file.

## Contents

1. [How it works](#how-it-works)
2. [Prerequisites](#prerequisites)
3. [Step 1: Namespace, ServiceAccount, token](#step-1-namespace-serviceaccount-token)
4. [Step 2: Run HaRP in Kubernetes mode](#step-2-run-harp-in-kubernetes-mode)
5. [Step 3: Confirm HaRP sees the cluster](#step-3-confirm-harp-sees-the-cluster)
6. [Step 4: Register the Kubernetes daemon](#step-4-register-the-kubernetes-daemon)
7. [Expose types](#expose-types)
8. [Step 5: Verify and install an ExApp](#step-5-verify-and-install-an-exapp)
9. [HaRP `HP_K8S_*` reference](#harp-hp_k8s_-reference)
10. [Troubleshooting](#troubleshooting)

## How it works

AppAPI never talks to the Kubernetes API. The `kubernetes-install` deploy backend (`lib/DeployActions/KubernetesActions.php`)
is a thin HTTP client: it POSTs high-level payloads (`create`, `start`, `stop`, `expose`, `remove`, ...) to
**HaRP** at `{protocol}://{host}/exapps/app_api/k8s/...`, and HaRP does all the cluster work.

- **HaRP runs outside the cluster** as a container that reaches the Kubernetes API server over the network,
  using a ServiceAccount bearer token. It is not deployed as an in-cluster workload.
- **HaRP creates the objects**: a Deployment, a Service (except for `manual` expose), a Pod, and a PVC per
  ExApp, in a namespace HaRP manages (`nextcloud-exapps` in every reference setup). `start` scales the
  Deployment to 1 replica, `stop` scales it to 0.
- **A Kubernetes daemon is always a HaRP daemon** (`--k8s` requires `--harp`). The AppAPI-to-HaRP shared key
  is stored encrypted and sent as the `harp-shared-key` header, exactly as for a Docker HaRP daemon.
- **ExApps are always reached through HaRP routing** at `{nextcloud_url}/exapps/{appId}`, never at a direct
  pod address.

Because HaRP is off-cluster, how HaRP reaches each ExApp depends on the Service type you choose
(see [Expose types](#expose-types)); that is the main decision on Kubernetes.

> The exact Deployment/Service/PVC object names, and the `HP_K8S_*` default values, are internal to HaRP and
> are not defined in the `app_api` repo. The stable, verifiable anchors are the namespace and the label
> `app.kubernetes.io/component=exapp` on ExApp Deployments and Services. Facts here are drawn from `KubernetesActions.php`,
> the repo's `tests-deploy-k8s*.yml` workflows, and HaRP's `development/redeploy_host_k8s.sh`; HaRP's README
> has no Kubernetes section to cite.

## Prerequisites

- Nextcloud 34+ with admin access.
- A Kubernetes cluster and `kubectl` access to it.
- A host to run the HaRP container that can reach the cluster's API server, and can reach the ExApp Service
  addresses for your chosen expose type (see [Expose types](#expose-types)). Running HaRP on a cluster node is
  the simplest way to satisfy both.
- A namespace and a ServiceAccount token for HaRP (Step 1).

## Step 1: Namespace, ServiceAccount, token

HaRP authenticates to the API server as a namespaced ServiceAccount. The reference setups grant it the
built-in `cluster-admin` role for convenience:

```bash
kubectl create namespace nextcloud-exapps
kubectl -n nextcloud-exapps create serviceaccount harp-sa
kubectl create clusterrolebinding harp-admin \
    --clusterrole=cluster-admin \
    --serviceaccount=nextcloud-exapps:harp-sa
# Mint a token; use a long duration for a persistent daemon.
TOKEN="$(kubectl -n nextcloud-exapps create token harp-sa --duration=8760h)"
```

The cluster may cap the token TTL (via the API server's `--service-account-max-token-expiration`); check the
issued token's expiry, or use a Secret-based ServiceAccount token if you need a long-lived daemon.

> `cluster-admin` is broad. It is what the project's CI uses, and it is fine for a dedicated or test cluster.
> The project does **not** publish a least-privilege Role. To restrict HaRP, HaRP needs to manage, in the
> `nextcloud-exapps` namespace, at least: `deployments` (apps API group) and `services`, `pods`, `pods/log`,
> `persistentvolumeclaims`, `secrets`, `configmaps` (core API group), with `create`/`get`/`list`/`watch`/`update`/`delete`.
> Author a namespaced `Role` + `RoleBinding` granting those, then validate with a real ExApp deploy: if you see
> `forbidden` errors in the HaRP logs, add the missing resource/verb. This set is inferred from
> `KubernetesActions` behavior, not from a published manifest, so treat it as a starting point.

## Step 2: Run HaRP in Kubernetes mode

Run HaRP outside the cluster and point it at the API server. Beyond the normal `HP_SHARED_KEY` /
`NC_INSTANCE_URL`, set `HP_K8S_ENABLED`, `HP_K8S_API_SERVER`, `HP_K8S_BEARER_TOKEN`, `HP_K8S_NAMESPACE`, and
(for the self-signed certs typical of k3s/kind) `HP_K8S_VERIFY_SSL="false"`.

```bash
docker run -d \
    --name appapi-harp -h appapi-harp \
    --restart unless-stopped \
    --network host \
    -e HP_SHARED_KEY="<CHOOSE_A_STRONG_ASCII_SECRET>" \
    -e NC_INSTANCE_URL="<nextcloud-url-reachable-from-harp>" \
    -e HP_K8S_ENABLED="true" \
    -e HP_K8S_API_SERVER="https://<api-server-host>:6443" \
    -e HP_K8S_BEARER_TOKEN="$TOKEN" \
    -e HP_K8S_NAMESPACE="nextcloud-exapps" \
    -e HP_K8S_VERIFY_SSL="false" \
    ghcr.io/nextcloud/nextcloud-appapi-harp:release
```

- `--network host` on a **real cluster node** (k3s/kubeadm) is the simplest choice: HaRP reaches the API
  server (often `https://127.0.0.1:6443`) and, with the `clusterip` expose type, routes to cluster IPs via
  kube-proxy. On **kind**, a remote cluster, or a managed cluster, HaRP is off-node: ClusterIPs are not
  routable and the API server is elsewhere, so choose `nodeport` (or `manual`), not `clusterip` (see
  [Expose types](#expose-types)).
- Find the API server URL with `kubectl config view --minify -o jsonpath='{.clusters[0].cluster.server}'`.
- `HP_K8S_VERIFY_SSL="false"` is common for self-signed cluster certs (k3s/kind). Prefer verified TLS in
  production and only disable it on a trusted network.
- If ExApp pods must resolve a hostname (for example your Nextcloud host) that cluster DNS does not know, add
  `-e HP_K8S_HOST_ALIASES="<hostname>:<ip>"`; HaRP injects it as pod `hostAliases`.

## Step 3: Confirm HaRP sees the cluster

Before registering, smoke-test that HaRP is up and its Kubernetes backend can reach the API server. HaRP's
`/exapps/app_api/info` reports a `kubernetes` block with `enabled` and `reachable`:

```bash
curl -sf http://<harp-host>:8780/exapps/app_api/info \
    -H "harp-shared-key: <CHOOSE_A_STRONG_ASCII_SECRET>" \
    | grep -Eq '"reachable" *: *true' && echo "HaRP K8s reachable"
```

The authoritative validation is the register-time daemon check (and the admin UI **Check connection**), which
enforces both `enabled` and `reachable`. If the curl or the daemon check fails, fix it now (see
[Troubleshooting](#troubleshooting)).

## Step 4: Register the Kubernetes daemon

Kubernetes daemons are managed via `occ` only; the admin UI shows them read-only ("managed via CLI").
The positional order is `name display-name accepts-deploy-id protocol host nextcloud_url`. For Kubernetes use
`kubernetes-install`, protocol `http`, and HaRP's `:8780` HTTP frontend as `host`. `--k8s` requires `--harp`
and forces the deploy id to `kubernetes-install`; `--harp_frp_address` is not needed for Kubernetes.

```bash
occ app_api:daemon:register \
    k8s1 "Kubernetes" kubernetes-install http <harp-host>:8780 <nextcloud-url-reachable-from-exapps> \
    --harp --harp_shared_key "<CHOOSE_A_STRONG_ASCII_SECRET>" \
    --k8s --k8s_expose_type clusterip \
    --set-default
```

- `host` (`<harp-host>:8780`) is the HaRP endpoint, not an ExApp address. Use `https` + HaRP's `:8781` only if
  you run HaRP's HTTPS frontend with certs.
- `--harp_shared_key` must be byte-identical to HaRP's `HP_SHARED_KEY`.
- `<nextcloud-url-reachable-from-exapps>` becomes each ExApp's `NEXTCLOUD_URL`; it must resolve from inside the
  cluster.

## Expose types

`--k8s_expose_type` decides what Service HaRP creates and how HaRP (off-cluster) reaches the ExApp. This is the
core Kubernetes decision. AppAPI validates the flags; HaRP maps them to Service types.

| Expose type | HaRP creates | HaRP reaches the ExApp via | Required/related flags | Choose when |
|---|---|---|---|---|
| `clusterip` (default) | ClusterIP Service | the Service `clusterIP` (needs HaRP able to route cluster IPs, e.g. via kube-proxy on a node) | none | HaRP runs on a node or where ClusterIPs are routable |
| `nodeport` | NodePort Service | a node address and the node port | `--k8s_node_port` (30000-32767), `--k8s_node_address_type` (`InternalIP` default / `ExternalIP`), `--k8s_external_traffic_policy` (`Cluster`/`Local`) | HaRP is off-cluster but can reach node IPs |
| `loadbalancer` | LoadBalancer Service | the load-balancer IP | `--k8s_load_balancer_ip`, `--k8s_external_traffic_policy` | the cluster has a LB provider (cloud, MetalLB) |
| `manual` | Deployment only, **no Service** | `--k8s_upstream_host` (you manage the Service/address) | `--k8s_upstream_host` (**required**) | you create and manage the Service yourself |

Only the expose flags valid for the chosen type are accepted (for example `--k8s_node_port` is rejected unless
the type is `nodeport`; `manual` fails without `--k8s_upstream_host`).

## Step 5: Verify and install an ExApp

```bash
occ app_api:daemon:list                 # the k8s daemon is listed and default
occ app_api:app:register <appid> k8s1 --wait-finish   # or omit the daemon to use the default
occ app_api:app:list                    # <appid> (<name>): <version> [enabled]
```

Inspect what HaRP created in the cluster (ExApp Deployments carry `app.kubernetes.io/component=exapp`):

```bash
# ExApp Deployments and Services carry the component=exapp label:
kubectl -n nextcloud-exapps get deploy,svc -l app.kubernetes.io/component=exapp -o wide
# Pods and PVCs are not labelled that way; list them for the namespace:
kubectl -n nextcloud-exapps get pods,pvc -o wide
```

Note: on Kubernetes, ExApp `--mount` options are recorded but not forwarded to the container; persistent data
is handled by a HaRP-side PVC (exposed to the ExApp as `APP_PERSISTENT_STORAGE`). GPU ExApps still use
`--compute_device cuda|rocm` on the daemon.

## HaRP `HP_K8S_*` reference

Set on the HaRP container (Step 2). Defaults are not documented in any HaRP source; the values below are what
the reference setups use.

| Env var | Meaning | Required |
|---|---|---|
| `HP_K8S_ENABLED` | Put HaRP into Kubernetes mode (otherwise Docker mode) | yes, `"true"` |
| `HP_K8S_API_SERVER` | Kubernetes API server URL HaRP calls | yes |
| `HP_K8S_BEARER_TOKEN` | ServiceAccount token HaRP authenticates with | yes |
| `HP_K8S_NAMESPACE` | Namespace HaRP creates/manages ExApp objects in | yes (`nextcloud-exapps`) |
| `HP_K8S_VERIFY_SSL` | Verify the API server TLS cert | optional (reference setups use `"false"`) |
| `HP_K8S_HOST_ALIASES` | `hostname:ip` entries injected as pod `hostAliases` | optional |

## Troubleshooting

The daemon check (`occ`, and the admin UI **Check connection**) surfaces HaRP's own Kubernetes status. The
common cases map directly to HaRP env:

- **"HaRP version is too old and does not report Kubernetes support"**: update the HaRP image (`:release`).
- **"Kubernetes backend is disabled in HaRP"**: `HP_K8S_ENABLED` is not `true`. Set it and restart HaRP.
- **"HaRP cannot reach the Kubernetes API server"**: check `HP_K8S_API_SERVER` (reachable from the HaRP
  container), the bearer token, and network/TLS (`HP_K8S_VERIFY_SSL`).
- **`forbidden` errors in HaRP logs during deploy**: the ServiceAccount token lacks permissions in the
  namespace. Use `cluster-admin` (Step 1) or add the missing resource/verb to your Role.
- **ExApp Deployment exists but HaRP cannot reach the ExApp**: the expose type does not match your topology.
  With `clusterip`, HaRP (off-cluster) cannot route to the ClusterIP unless it runs on a node or has routes;
  switch to `nodeport`/`loadbalancer`, use `manual` with `--k8s_upstream_host`, or run HaRP on a node.
- **ExApp pod cannot resolve your Nextcloud host**: add it via `HP_K8S_HOST_ALIASES`, or ensure
  `<nextcloud-url-reachable-from-exapps>` uses a name cluster DNS can resolve.
- **Image pull fails in the cluster**: private registry or missing pull secret; configure registry access in
  the namespace.

For the daemon-register flag reference and general ExApp lifecycle, see [`../../AGENTS.md`](../../AGENTS.md).
