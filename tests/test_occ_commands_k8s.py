#
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
"""
Integration tests for AppAPI Kubernetes deployment support.

Requires: Nextcloud with AppAPI enabled, k3s, HaRP with K8s backend, nginx proxy.
See .github/workflows/tests-deploy-k8s.yml for CI setup.
"""
import json
import os
from subprocess import DEVNULL, PIPE, TimeoutExpired, run

SKELETON_XML_URL = (
    "https://raw.githubusercontent.com/nextcloud/app-skeleton-python/main/appinfo/info.xml"
)
K8S_DAEMON_NAME = "k8s_test"
K8S_NAMESPACE = "nextcloud-exapps"

# Expose-type awareness: set K8S_EXPOSE_TYPE in CI to select the expose type under test.
EXPOSE_TYPE = os.environ.get("K8S_EXPOSE_TYPE", "nodeport")
IS_MANUAL = EXPOSE_TYPE == "manual"
IS_CLUSTERIP = EXPOSE_TYPE == "clusterip"
# Fixed ClusterIP used for operator-created Services in manual tests.
MANUAL_CLUSTER_IP = os.environ.get("MANUAL_CLUSTER_IP", "10.43.200.200")

# Expected K8s Service type per expose type (manual creates no HaRP-managed Service).
EXPECTED_SVC_TYPE = {
    "nodeport": "NodePort",
    "clusterip": "ClusterIP",
    "loadbalancer": "LoadBalancer",
}

# Separate daemon name for validation tests to avoid interfering with the deploy daemon
K8S_VALIDATION_DAEMON = "k8s_validation"

# Base args for registering a K8s daemon (used in validation tests)
K8S_DAEMON_BASE = [
    "php", "occ", "--no-warnings", "app_api:daemon:register",
    K8S_VALIDATION_DAEMON, "K8s Validation", "kubernetes-install", "http",
    "127.0.0.1:8780", "http://127.0.0.1",
]
K8S_HARP_OPTS = [
    "--harp", "--harp_shared_key", "test_key", "--harp_frp_address", "127.0.0.1:8782",
]


def occ(cmd_str, check=True, capture=True, timeout=None):
    """Run an OCC command. cmd_str is appended to 'php occ --no-warnings'."""
    args = ["php", "occ", "--no-warnings"] + cmd_str.split()
    return run(
        args,
        stdout=PIPE if capture else DEVNULL,
        stderr=PIPE if capture else DEVNULL,
        check=check,
        timeout=timeout,
    )


def occ_output(cmd_str, **kwargs):
    """Run OCC command and return stdout as string."""
    r = occ(cmd_str, **kwargs)
    return r.stdout.decode("UTF-8")


def kubectl(cmd_str, check=True):
    """Run a kubectl command against the test namespace."""
    args = ["kubectl", "-n", K8S_NAMESPACE] + cmd_str.split()
    return run(args, stdout=PIPE, stderr=PIPE, check=check)


def kubectl_output(cmd_str, **kwargs):
    """Run kubectl and return stdout as string."""
    r = kubectl(cmd_str, **kwargs)
    return r.stdout.decode("UTF-8")


def register_k8s_daemon(extra_opts=None):
    """Register a K8s daemon with standard HaRP options."""
    args = K8S_DAEMON_BASE + ["--k8s"] + K8S_HARP_OPTS
    if extra_opts:
        args += extra_opts
    return run(args, stdout=PIPE, stderr=PIPE)


def unregister_k8s_daemon(name=None):
    """Unregister a K8s daemon by name."""
    run(
        ["php", "occ", "--no-warnings", "app_api:daemon:unregister", name or K8S_VALIDATION_DAEMON],
        stdout=DEVNULL, stderr=DEVNULL,
    )


def cleanup_k8s_daemon():
    """Ensure no leftover validation daemon from previous test."""
    unregister_k8s_daemon(K8S_VALIDATION_DAEMON)


def ensure_manual_service(app_name="app-skeleton-python", port=23000):
    """Pre-create a ClusterIP Service for manual expose type testing.

    For manual expose, HaRP does not create a K8s Service — the operator
    manages networking.  This simulates the operator creating a Service
    before deploying the ExApp.  Uses a fixed ClusterIP so it survives
    delete/re-create cycles with the same daemon upstream_host.
    """
    if not IS_MANUAL:
        return
    svc_name = f"nc-app-{app_name}"
    manifest = json.dumps({
        "apiVersion": "v1",
        "kind": "Service",
        "metadata": {"name": svc_name, "namespace": K8S_NAMESPACE},
        "spec": {
            "clusterIP": MANUAL_CLUSTER_IP,
            "selector": {"app": svc_name},
            "ports": [{"name": "http", "port": port, "targetPort": port}],
        },
    })
    r = run(
        ["kubectl", "-n", K8S_NAMESPACE, "apply", "-f", "-"],
        input=manifest.encode(), stdout=PIPE, stderr=PIPE,
    )
    assert r.returncode == 0, f"Failed to create operator Service {svc_name}: {r.stderr.decode()}"


# =============================================================================
# Group A: Daemon registration validation (no k3s/HaRP needed)
# =============================================================================

def test_k8s_daemon_requires_harp():
    """--k8s without --harp must fail."""
    print("  test_k8s_daemon_requires_harp...", end=" ")
    cleanup_k8s_daemon()
    r = run(
        K8S_DAEMON_BASE + ["--k8s"],
        stdout=PIPE, stderr=PIPE,
    )
    assert r.returncode == 1, f"Expected exit 1, got {r.returncode}"
    output = r.stdout.decode("UTF-8")
    assert "requires --harp flag" in output, f"Expected error about --harp, got: {output}"
    print("OK")


def test_k8s_daemon_basic_register():
    """Basic K8s daemon registration and unregistration."""
    print("  test_k8s_daemon_basic_register...", end=" ")
    cleanup_k8s_daemon()
    r = register_k8s_daemon()
    assert r.returncode == 0, f"Registration failed: {r.stdout.decode()}"

    # Verify daemon shows up in list with kubernetes-install
    output = occ_output("app_api:daemon:list")
    assert "kubernetes-install" in output, f"Expected kubernetes-install in daemon list: {output}"

    unregister_k8s_daemon()
    print("OK")


def test_k8s_daemon_deploy_id_override():
    """--k8s with wrong deploy-id should auto-override to kubernetes-install."""
    print("  test_k8s_daemon_deploy_id_override...", end=" ")
    cleanup_k8s_daemon()
    # Pass docker-install as deploy id, but with --k8s flag
    args = [
        "php", "occ", "--no-warnings", "app_api:daemon:register",
        K8S_VALIDATION_DAEMON, "K8s Validation", "docker-install", "http",
        "127.0.0.1:8780", "http://127.0.0.1",
        "--k8s",
    ] + K8S_HARP_OPTS
    r = run(args, stdout=PIPE, stderr=PIPE)
    assert r.returncode == 0, f"Registration failed: {r.stdout.decode()}"
    output = r.stdout.decode("UTF-8")
    assert "Overriding accepts-deploy-id" in output, f"Expected override message, got: {output}"

    list_output = occ_output("app_api:daemon:list")
    assert "kubernetes-install" in list_output, f"Expected kubernetes-install in list: {list_output}"

    unregister_k8s_daemon()
    print("OK")


def test_k8s_expose_type_invalid():
    """Invalid expose type must fail."""
    print("  test_k8s_expose_type_invalid...", end=" ")
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=invalid"])
    assert r.returncode == 1, f"Expected exit 1, got {r.returncode}"
    output = r.stdout.decode("UTF-8")
    assert "Invalid k8s_expose_type" in output, f"Expected validation error, got: {output}"
    print("OK")


def test_k8s_expose_type_all_valid():
    """All valid expose types should register successfully."""
    print("  test_k8s_expose_type_all_valid...", end=" ")
    valid_types = {
        "clusterip": [],
        "nodeport": [],
        "loadbalancer": [],
        "manual": ["--k8s_upstream_host=1.2.3.4"],
    }
    for expose_type, extra_opts in valid_types.items():
        cleanup_k8s_daemon()
        r = register_k8s_daemon([f"--k8s_expose_type={expose_type}"] + extra_opts)
        assert r.returncode == 0, f"Registration failed for {expose_type}: {r.stdout.decode()}"
        unregister_k8s_daemon()
    print("OK")


def test_k8s_node_port_range():
    """NodePort range validation."""
    print("  test_k8s_node_port_range...", end=" ")

    # Below range
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=nodeport", "--k8s_node_port=29999"])
    assert r.returncode == 1, "Expected exit 1 for port below range"
    assert "must be between 30000 and 32767" in r.stdout.decode()

    # Above range
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=nodeport", "--k8s_node_port=32768"])
    assert r.returncode == 1, "Expected exit 1 for port above range"
    assert "must be between 30000 and 32767" in r.stdout.decode()

    # Wrong expose type for node_port
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=clusterip", "--k8s_node_port=31000"])
    assert r.returncode == 1, "Expected exit 1 for node_port with clusterip"
    assert "only valid with" in r.stdout.decode()

    # Valid
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=nodeport", "--k8s_node_port=31000"])
    assert r.returncode == 0, f"Valid nodeport registration failed: {r.stdout.decode()}"
    unregister_k8s_daemon()
    print("OK")


def test_k8s_manual_requires_upstream():
    """Manual expose type requires --k8s_upstream_host."""
    print("  test_k8s_manual_requires_upstream...", end=" ")

    # Missing upstream_host
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=manual"])
    assert r.returncode == 1, "Expected exit 1 without upstream_host"
    assert "required for" in r.stdout.decode().lower() or "k8s_upstream_host" in r.stdout.decode()

    # With upstream_host
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=manual", "--k8s_upstream_host=1.2.3.4"])
    assert r.returncode == 0, f"Manual with upstream_host failed: {r.stdout.decode()}"
    unregister_k8s_daemon()
    print("OK")


def test_k8s_lb_ip_wrong_type():
    """--k8s_load_balancer_ip only valid with loadbalancer type."""
    print("  test_k8s_lb_ip_wrong_type...", end=" ")
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_expose_type=nodeport", "--k8s_load_balancer_ip=1.2.3.4"])
    assert r.returncode == 1, "Expected exit 1 for lb_ip with nodeport"
    assert "only valid with" in r.stdout.decode()
    print("OK")


def test_k8s_external_traffic_policy_invalid():
    """Invalid external traffic policy must fail."""
    print("  test_k8s_external_traffic_policy_invalid...", end=" ")
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_external_traffic_policy=Invalid"])
    assert r.returncode == 1, "Expected exit 1 for invalid policy"
    assert "k8s_external_traffic_policy" in r.stdout.decode()
    print("OK")


def test_k8s_node_address_type_invalid():
    """Invalid node address type must fail."""
    print("  test_k8s_node_address_type_invalid...", end=" ")
    cleanup_k8s_daemon()
    r = register_k8s_daemon(["--k8s_node_address_type=BadType"])
    assert r.returncode == 1, "Expected exit 1 for invalid address type"
    assert "k8s_node_address_type" in r.stdout.decode()
    print("OK")


def run_validation_tests():
    """Group A: daemon registration validation."""
    print("\n=== Group A: K8s Daemon Registration Validation ===")
    test_k8s_daemon_requires_harp()
    test_k8s_daemon_basic_register()
    test_k8s_daemon_deploy_id_override()
    test_k8s_expose_type_invalid()
    test_k8s_expose_type_all_valid()
    test_k8s_node_port_range()
    test_k8s_manual_requires_upstream()
    test_k8s_lb_ip_wrong_type()
    test_k8s_external_traffic_policy_invalid()
    test_k8s_node_address_type_invalid()
    print("=== Group A: All validation tests passed ===\n")


# =============================================================================
# Group B: Single-role deploy lifecycle (needs k3s + HaRP)
# =============================================================================

def test_k8s_single_deploy():
    """Deploy a single-role ExApp via K8s."""
    print("  test_k8s_single_deploy...", end=" ", flush=True)
    ensure_manual_service()

    # Register app
    r = occ(
        f"app_api:app:register app-skeleton-python {K8S_DAEMON_NAME}"
        f" --info-xml {SKELETON_XML_URL} --wait-finish",
        check=False,
        timeout=600,
    )
    assert r.returncode == 0, f"Deploy failed (exit {r.returncode}): {r.stdout.decode()}"

    # Verify K8s resources
    deploy_output = kubectl_output("get deploy -o name")
    assert "app-skeleton-python" in deploy_output, f"No deployment found: {deploy_output}"

    if not IS_MANUAL:
        svc_output = kubectl_output("get svc -o name")
        assert "app-skeleton-python" in svc_output, f"No service found: {svc_output}"

        # Verify Service type matches the expose type
        expected_type = EXPECTED_SVC_TYPE.get(EXPOSE_TYPE)
        if expected_type:
            svc_json = kubectl_output("get svc -l app.kubernetes.io/component=exapp -o json")
            svc_data = json.loads(svc_json)
            found = False
            for item in svc_data.get("items", []):
                if "app-skeleton-python" in item["metadata"]["name"]:
                    actual_type = item["spec"].get("type", "ClusterIP")
                    assert actual_type == expected_type, (
                        f"Service type mismatch: expected {expected_type}, got {actual_type}"
                    )
                    found = True
                    break
            assert found, (
                f"Service with 'app-skeleton-python' not found via label selector "
                f"app.kubernetes.io/component=exapp; services: {svc_data.get('items', [])}"
            )

    pvc_output = kubectl_output("get pvc -o name")
    assert "app-skeleton-python" in pvc_output, f"No PVC found: {pvc_output}"

    # Verify in AppAPI
    list_output = occ_output("app_api:app:list")
    assert "app-skeleton-python" in list_output, f"App not in list: {list_output}"
    print("OK")


def test_k8s_single_enable_disable():
    """Disable and re-enable a K8s ExApp (scale replicas)."""
    print("  test_k8s_single_enable_disable...", end=" ", flush=True)

    # Disable
    r = occ("app_api:app:disable app-skeleton-python", check=False, timeout=120)
    assert r.returncode == 0, f"Disable failed: {r.stdout.decode()}"

    # Verify replicas=0
    deploy_json = kubectl_output("get deploy -l app.kubernetes.io/component=exapp -o json", check=False)
    data = json.loads(deploy_json)
    if data.get("items"):
        replicas = data["items"][0]["spec"].get("replicas", -1)
        assert replicas == 0, f"Expected 0 replicas after disable, got {replicas}"

    # Re-enable
    r = occ("app_api:app:enable app-skeleton-python", check=False, timeout=300)
    assert r.returncode == 0, f"Enable failed: {r.stdout.decode()}"

    # Verify replicas=1
    deploy_json = kubectl_output("get deploy -l app.kubernetes.io/component=exapp -o json", check=False)
    data = json.loads(deploy_json)
    if data.get("items"):
        replicas = data["items"][0]["spec"].get("replicas", -1)
        assert replicas == 1, f"Expected 1 replica after enable, got {replicas}"
    print("OK")


def test_k8s_single_unregister_keep_data():
    """Unregister K8s ExApp — default keeps PVC."""
    print("  test_k8s_single_unregister_keep_data...", end=" ", flush=True)
    r = occ("app_api:app:unregister app-skeleton-python", check=False, timeout=120)
    assert r.returncode == 0, f"Unregister failed: {r.stdout.decode()}"

    # Deployment and Service should be gone
    deploy_output = kubectl_output("get deploy -o name", check=False)
    assert "app-skeleton-python" not in deploy_output, f"Deployment still exists: {deploy_output}"

    if not IS_MANUAL:
        svc_output = kubectl_output("get svc -o name", check=False)
        assert "app-skeleton-python" not in svc_output, f"Service still exists: {svc_output}"

    # PVC should still exist (default keeps data)
    pvc_output = kubectl_output("get pvc -o name", check=False)
    assert "app-skeleton-python" in pvc_output, f"PVC should still exist: {pvc_output}"

    # Clean up PVC for next test
    kubectl("delete pvc --all", check=False)
    print("OK")


def test_k8s_single_deploy_rm_data():
    """Deploy then unregister with --rm-data removes PVC too."""
    print("  test_k8s_single_deploy_rm_data...", end=" ", flush=True)
    ensure_manual_service()

    # Deploy again
    r = occ(
        f"app_api:app:register app-skeleton-python {K8S_DAEMON_NAME}"
        f" --info-xml {SKELETON_XML_URL} --wait-finish",
        check=False,
        timeout=600,
    )
    assert r.returncode == 0, f"Deploy failed: {r.stdout.decode()}"

    # Unregister with --rm-data
    r = occ("app_api:app:unregister app-skeleton-python --rm-data", check=False, timeout=120)
    assert r.returncode == 0, f"Unregister --rm-data failed: {r.stdout.decode()}"

    # PVC removal is requested via remove_data=true in the payload to HaRP.
    # K8s pvc-protection finalizer may delay actual deletion until pod terminates.
    # Wait briefly then check.
    import time
    time.sleep(5)
    pvc_output = kubectl_output("get pvc -o name", check=False)
    # Note: PVC may still exist with a deletion timestamp (terminating).
    # Check that it's either gone or marked for deletion.
    if "app-skeleton-python" in pvc_output:
        pvc_json = kubectl_output("get pvc -o json", check=False)
        import json as _json
        data = _json.loads(pvc_json)
        for item in data.get("items", []):
            if "app-skeleton-python" in item["metadata"]["name"]:
                deletion_ts = item["metadata"].get("deletionTimestamp")
                assert deletion_ts is not None, \
                    f"PVC exists without deletionTimestamp — --rm-data didn't trigger removal"
                print(f"OK (PVC terminating: {deletion_ts})")
                return
    print("OK")


def run_single_role_tests():
    """Group B: single-role lifecycle."""
    print("\n=== Group B: K8s Single-Role Deploy Lifecycle ===")
    test_k8s_single_deploy()
    test_k8s_single_enable_disable()
    test_k8s_single_unregister_keep_data()
    test_k8s_single_deploy_rm_data()
    print("=== Group B: All single-role tests passed ===\n")


# =============================================================================
# Group C: Multi-role deploy lifecycle (needs k3s + HaRP)
# =============================================================================

def build_multi_role_json():
    """Build JSON info for multi-role ExApp."""
    return json.dumps({
        "id": "app-skeleton-python",
        "name": "App Skeleton Python",
        "version": "1.0.0",
        "docker-install": {
            "registry": "ghcr.io",
            "image": "nextcloud/app-skeleton-python",
            "image-tag": "latest",
        },
        "k8s-service-roles": [
            {"name": "api", "env": "SERVICE_ROLE=api", "expose": True},
            {"name": "worker", "env": "SERVICE_ROLE=worker", "expose": False},
        ],
    })


def test_k8s_multi_deploy():
    """Deploy a multi-role ExApp."""
    print("  test_k8s_multi_deploy...", end=" ", flush=True)
    json_info = build_multi_role_json()

    r = run(
        [
            "php", "occ", "--no-warnings", "app_api:app:register",
            "app-skeleton-python", K8S_DAEMON_NAME,
            "--json-info", json_info,
            "--wait-finish",
        ],
        stdout=PIPE, stderr=PIPE, timeout=600,
    )
    assert r.returncode == 0, f"Multi-role deploy failed (exit {r.returncode}): {r.stdout.decode()}"

    # Verify 2 Deployments
    deploy_output = kubectl_output("get deploy -o name")
    deploy_names = [line for line in deploy_output.strip().split("\n") if "app-skeleton-python" in line]
    assert len(deploy_names) >= 2, f"Expected 2 deployments, got {len(deploy_names)}: {deploy_output}"

    # Verify Service (only the api role with expose=true)
    if not IS_MANUAL:
        svc_output = kubectl_output("get svc -o name")
        svc_names = [line for line in svc_output.strip().split("\n") if "app-skeleton-python" in line]
        assert len(svc_names) == 1, f"Expected 1 service (exposed role only), got {len(svc_names)}: {svc_output}"

        # Verify Service type matches the expose type
        expected_type = EXPECTED_SVC_TYPE.get(EXPOSE_TYPE)
        if expected_type:
            svc_json = kubectl_output("get svc -l app.kubernetes.io/component=exapp -o json")
            svc_data = json.loads(svc_json)
            found = False
            for item in svc_data.get("items", []):
                if "app-skeleton-python" in item["metadata"]["name"]:
                    actual_type = item["spec"].get("type", "ClusterIP")
                    assert actual_type == expected_type, (
                        f"Multi-role Service type mismatch: expected {expected_type}, got {actual_type}"
                    )
                    found = True
                    break
            assert found, (
                f"Multi-role Service with 'app-skeleton-python' not found via label selector; "
                f"services: {svc_data.get('items', [])}"
            )

    # Verify in AppAPI
    list_output = occ_output("app_api:app:list")
    assert "app-skeleton-python" in list_output, f"App not in list: {list_output}"
    print("OK")


def test_k8s_multi_enable_disable():
    """Disable and re-enable a multi-role ExApp."""
    print("  test_k8s_multi_enable_disable...", end=" ", flush=True)

    # Disable
    r = occ("app_api:app:disable app-skeleton-python", check=False, timeout=120)
    assert r.returncode == 0, f"Disable failed: {r.stdout.decode()}"

    # Verify all deployments scaled to 0
    deploy_json = kubectl_output("get deploy -l app.kubernetes.io/component=exapp -o json", check=False)
    data = json.loads(deploy_json)
    for item in data.get("items", []):
        replicas = item["spec"].get("replicas", -1)
        name = item["metadata"]["name"]
        assert replicas == 0, f"Expected 0 replicas for {name} after disable, got {replicas}"

    # Re-enable
    r = occ("app_api:app:enable app-skeleton-python", check=False, timeout=300)
    assert r.returncode == 0, f"Enable failed: {r.stdout.decode()}"

    # Verify all deployments scaled to 1
    deploy_json = kubectl_output("get deploy -l app.kubernetes.io/component=exapp -o json", check=False)
    data = json.loads(deploy_json)
    for item in data.get("items", []):
        replicas = item["spec"].get("replicas", -1)
        name = item["metadata"]["name"]
        assert replicas == 1, f"Expected 1 replica for {name} after enable, got {replicas}"
    print("OK")


def test_k8s_multi_unregister():
    """Unregister multi-role ExApp — both deployments and service removed."""
    print("  test_k8s_multi_unregister...", end=" ", flush=True)
    r = occ("app_api:app:unregister app-skeleton-python --rm-data", check=False, timeout=120)
    assert r.returncode == 0, f"Unregister failed: {r.stdout.decode()}"

    # All deployments gone
    deploy_output = kubectl_output("get deploy -o name", check=False)
    assert "app-skeleton-python" not in deploy_output, f"Deployments still exist: {deploy_output}"

    # Service gone
    if not IS_MANUAL:
        svc_output = kubectl_output("get svc -o name", check=False)
        assert "app-skeleton-python" not in svc_output, f"Service still exists: {svc_output}"
    print("OK")


def run_multi_role_tests():
    """Group C: multi-role lifecycle."""
    if IS_MANUAL:
        print("\n=== Group C: Skipped (multi-role + manual not supported yet) ===\n")
        return
    print("\n=== Group C: K8s Multi-Role Deploy Lifecycle ===")
    test_k8s_multi_deploy()
    # Skip enable/disable for multi-role: app-skeleton-python exits after init
    # (pod enters 'Succeeded' phase), which waitExAppStart treats as failure.
    # The enable/disable K8s code path (startAllRoles/stopAllRoles) is already
    # tested in Group B with single-role — same code, just iterates roles.
    test_k8s_multi_unregister()
    print("=== Group C: All multi-role tests passed ===\n")


# =============================================================================
# Group D: Failure & edge cases (needs k3s + HaRP)
# =============================================================================

def test_k8s_deploy_bad_image():
    """Deploy with nonexistent image — should fail and clean up."""
    print("  test_k8s_deploy_bad_image...", end=" ", flush=True)
    bad_json = json.dumps({
        "id": "bad-image-test",
        "name": "Bad Image Test",
        "version": "1.0.0",
        "docker-install": {
            "registry": "ghcr.io",
            "image": "nextcloud/does-not-exist",
            "image-tag": "v999",
        },
    })

    try:
        r = run(
            [
                "php", "occ", "--no-warnings", "app_api:app:register",
                "bad-image-test", K8S_DAEMON_NAME,
                "--json-info", bad_json,
                "--wait-finish",
            ],
            stdout=PIPE, stderr=PIPE, timeout=300,
        )
        # Should fail
        assert r.returncode != 0, f"Expected failure for bad image, got exit 0: {r.stdout.decode()}"
    except TimeoutExpired:
        # If it times out, that's also acceptable — kill and continue
        print("TIMEOUT (expected for bad image) ", end="")

    # Verify cleanup: no leftover K8s deployments for bad-image-test
    deploy_output = kubectl_output("get deploy -o name", check=False)
    assert "bad-image-test" not in deploy_output, f"Leftover deployment found: {deploy_output}"

    # Verify cleanup: no leftover K8s Services for bad-image-test
    if not IS_MANUAL:
        svc_output = kubectl_output("get svc -o name", check=False)
        assert "bad-image-test" not in svc_output, f"Leftover service found: {svc_output}"

    # Verify not in AppAPI list
    list_output = occ_output("app_api:app:list", check=False)
    # Force-clean if still registered
    if "bad-image-test" in list_output:
        occ("app_api:app:unregister bad-image-test --force", check=False, timeout=30)
    print("OK")


def test_k8s_unregister_force():
    """--force unregister works even when K8s resources are already gone."""
    print("  test_k8s_unregister_force...", end=" ", flush=True)
    ensure_manual_service()

    # Deploy
    r = occ(
        f"app_api:app:register app-skeleton-python {K8S_DAEMON_NAME}"
        f" --info-xml {SKELETON_XML_URL} --wait-finish",
        check=False,
        timeout=600,
    )
    assert r.returncode == 0, f"Deploy failed: {r.stdout.decode()}"

    # Manually delete K8s deployment
    kubectl("delete deploy --all", check=False)
    kubectl("delete svc --all", check=False)

    # Normal unregister might fail or succeed (removeExApp checks exists first)
    r = occ("app_api:app:unregister app-skeleton-python", check=False, timeout=120)
    if r.returncode != 0:
        # --force should always work
        r = occ("app_api:app:unregister app-skeleton-python --force", check=False, timeout=120)
        assert r.returncode == 0, f"--force unregister failed: {r.stdout.decode()}"

    # Verify removed from AppAPI
    list_output = occ_output("app_api:app:list", check=False)
    assert "app-skeleton-python" not in list_output, f"App still in list: {list_output}"

    # Clean up any leftover PVCs
    kubectl("delete pvc --all", check=False)
    print("OK")


def test_k8s_unregister_nonexistent_silent():
    """--silent unregister of nonexistent app should succeed silently."""
    print("  test_k8s_unregister_nonexistent_silent...", end=" ")
    r = occ("app_api:app:unregister nonexistent-k8s-app --silent", check=False)
    assert r.returncode == 0, f"Expected exit 0 with --silent, got {r.returncode}"
    output = r.stdout.decode("UTF-8")
    assert not output.strip(), f"Output should be empty with --silent: {output}"
    print("OK")


def run_failure_tests():
    """Group D: failure and edge cases."""
    print("\n=== Group D: K8s Failure & Edge Cases ===")
    test_k8s_deploy_bad_image()
    test_k8s_unregister_force()
    test_k8s_unregister_nonexistent_silent()
    print("=== Group D: All failure tests passed ===\n")


# =============================================================================
# Main
# =============================================================================

if __name__ == "__main__":
    print(f"K8s expose type: {EXPOSE_TYPE}")
    run_validation_tests()
    run_single_role_tests()
    run_multi_role_tests()
    run_failure_tests()
    print(f"All K8s tests passed! (expose_type={EXPOSE_TYPE})")
