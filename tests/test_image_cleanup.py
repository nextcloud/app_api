#
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
"""End-to-end test for the ExApp Docker image cleanup feature.

Exercises AppAPI -> HaRP -> Docker for real. The test assumes a HaRP-backed Docker
daemon is already registered in AppAPI (matching the existing tests-deploy.yml setup
where `harp_proxy` is created before the test step runs).

What we cover:
  1. Default uninstall queues an OrphanedImageCleanupJob; running it deletes the image.
  2. `--purge-now` deletes immediately, no queued job left over.
  3. `--keep-image` skips cleanup entirely; nothing queued, image stays.
  4. Reinstall before the queued job fires keeps the image (Docker returns 409).

Requires:
  * `docker` CLI in PATH (used to inspect image state on the daemon host).
  * env DAEMON_NAME (default "harp_proxy") - the HaRP daemon registered in AppAPI.
  * env APP_ID / IMAGE_NAME_GREP (default "app-skeleton-python") - the test ExApp.
  * env OCC_PREFIX (optional) - shell prefix to invoke `occ`. Default "php occ", which
    works when the test runs from a Nextcloud server root. In CI where Nextcloud lives
    in a Docker container, set e.g. "docker exec nextcloud-docker sudo -u www-data php occ".
"""

import json
import os
import shlex
import sys
import time
from subprocess import DEVNULL, PIPE, CalledProcessError, run

DAEMON_NAME = os.environ.get("DAEMON_NAME", "harp_proxy")
APP_ID = os.environ.get("APP_ID", "app-skeleton-python")
IMAGE_NAME_GREP = os.environ.get("IMAGE_NAME_GREP", APP_ID)
OCC_PREFIX = shlex.split(os.environ.get("OCC_PREFIX", "php occ"))
HARP_CONTAINER = os.environ.get("HARP_CONTAINER_NAME", "appapi-harp")
HARP_DOCKER_PORT = os.environ.get("HARP_DOCKER_PORT", "24000")
SKELETON_XML_URL = (
    "https://raw.githubusercontent.com/nextcloud/app-skeleton-python/main/appinfo/info.xml"
)


def occ(*args, check=True, capture=False):
    """Invoke the configured occ command with the given args."""
    cmd = [*OCC_PREFIX, "--no-warnings", *args]
    if capture:
        return run(cmd, stdout=PIPE, stderr=PIPE, check=check, text=True)
    return run(cmd, stdout=DEVNULL, stderr=DEVNULL, check=check)


def harp_image_remove(image_ref: str) -> dict:
    """Hit HaRP's /docker/exapp/image_remove endpoint directly (bypasses AppAPI).

    Used by the multi-tag scenario to assert HaRP's bytes_freed accuracy without
    spinning up an ExApp. Runs from inside the appapi-harp container so we don't
    need to expose port 8200 on the host.
    """
    body = json.dumps({"image_ref": image_ref})
    r = run(
        [
            "docker", "exec", HARP_CONTAINER, "wget", "-q", "-O-",
            "--header", f"docker-engine-port: {HARP_DOCKER_PORT}",
            "--header", "content-type: application/json",
            "--post-data", body,
            "http://127.0.0.1:8200/docker/exapp/image_remove",
        ],
        stdout=PIPE,
        stderr=PIPE,
        text=True,
        check=True,
    )
    return json.loads(r.stdout)


def image_present(grep: str) -> bool:
    """Check the local Docker daemon for any image whose repo:tag matches `grep`."""
    r = run(
        ["docker", "images", "--format", "{{.Repository}}:{{.Tag}}"],
        stdout=PIPE,
        stderr=DEVNULL,
        text=True,
        check=True,
    )
    for line in r.stdout.splitlines():
        if grep in line:
            return True
    return False


def list_pending_jobs(class_substring: str) -> list[dict]:
    """Return pending jobs whose class contains `class_substring`."""
    r = occ("background-job:list", "--output=json", capture=True)
    try:
        data = json.loads(r.stdout)
    except json.JSONDecodeError:
        return []
    jobs = data if isinstance(data, list) else data.get("data", [])
    return [j for j in jobs if class_substring in j.get("class", "")]


def execute_pending_cleanup_jobs() -> int:
    """Force-run all pending OrphanedImageCleanupJob entries. Returns count executed."""
    jobs = list_pending_jobs("OrphanedImageCleanupJob")
    for j in jobs:
        occ("background-job:execute", str(j["id"]), "--force-execute")
    return len(jobs)


def deploy_app() -> None:
    """Register the skeleton ExApp on the HaRP daemon and wait for it to come up."""
    occ(
        "app_api:app:register",
        APP_ID,
        DAEMON_NAME,
        "--info-xml",
        SKELETON_XML_URL,
        "--wait-finish",
    )


def assert_image_eventually_present(grep: str, timeout: float = 30.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if image_present(grep):
            return
        time.sleep(1.0)
    raise AssertionError(f"Image matching '{grep}' did not appear within {timeout}s")


def assert_image_eventually_gone(grep: str, timeout: float = 30.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if not image_present(grep):
            return
        time.sleep(1.0)
    raise AssertionError(f"Image matching '{grep}' was still present after {timeout}s")


def reset_state() -> None:
    """Best-effort uninstall + image purge so each scenario starts clean."""
    try:
        occ("app_api:app:unregister", APP_ID, "--silent", "--force", "--purge-now", check=False)
    except CalledProcessError:
        pass
    # Drop any stray queued jobs from prior runs of this test.
    for j in list_pending_jobs("OrphanedImageCleanupJob"):
        run([*OCC_PREFIX, "--no-warnings", "background-job:delete", str(j["id"])], check=False, stdout=DEVNULL, stderr=DEVNULL)


def scenario_default_uninstall_runs_via_queued_job() -> None:
    print("scenario 1: default uninstall queues a job; running it deletes the image")
    deploy_app()
    assert_image_eventually_present(IMAGE_NAME_GREP)

    occ("app_api:app:unregister", APP_ID)
    # With grace=0 a job is still queued (runAfter = now); the cron didn't pick it up yet.
    pending = list_pending_jobs("OrphanedImageCleanupJob")
    assert len(pending) == 1, f"Expected 1 queued cleanup job, got {len(pending)}"

    executed = execute_pending_cleanup_jobs()
    assert executed == 1
    assert_image_eventually_gone(IMAGE_NAME_GREP)
    assert list_pending_jobs("OrphanedImageCleanupJob") == [], "Job did not self-remove"


def scenario_purge_now_deletes_immediately() -> None:
    print("scenario 2: --purge-now deletes immediately, no queued job")
    deploy_app()
    assert_image_eventually_present(IMAGE_NAME_GREP)

    occ("app_api:app:unregister", APP_ID, "--purge-now")
    pending = list_pending_jobs("OrphanedImageCleanupJob")
    assert pending == [], f"Expected no queued jobs, got {pending}"
    assert_image_eventually_gone(IMAGE_NAME_GREP)


def scenario_keep_image_skips_cleanup() -> None:
    print("scenario 3: --keep-image leaves the image alone, no queued job")
    deploy_app()
    assert_image_eventually_present(IMAGE_NAME_GREP)

    occ("app_api:app:unregister", APP_ID, "--keep-image")
    pending = list_pending_jobs("OrphanedImageCleanupJob")
    assert pending == [], f"Expected no queued jobs, got {pending}"
    # Image must still be there after a beat.
    time.sleep(2)
    assert image_present(IMAGE_NAME_GREP), "--keep-image should leave the image in place"


def scenario_reinstall_before_cron_keeps_image() -> None:
    print("scenario 4: reinstall before queued job fires keeps the image (Docker 409)")
    deploy_app()
    assert_image_eventually_present(IMAGE_NAME_GREP)

    occ("app_api:app:unregister", APP_ID)
    assert len(list_pending_jobs("OrphanedImageCleanupJob")) == 1

    # Reinstall same version BEFORE the cron picks up the job. The new container will
    # reference the same image; when the job runs it should see Docker 409 and skip.
    deploy_app()

    executed = execute_pending_cleanup_jobs()
    assert executed == 1
    # Image must still be on disk because the new container references it.
    time.sleep(2)
    assert image_present(IMAGE_NAME_GREP), "Image should survive when a new container references it"
    # The job entry should still have been removed (QueuedJob self-removes).
    assert list_pending_jobs("OrphanedImageCleanupJob") == []


def scenario_multi_tag_reports_zero_until_last_tag_removed() -> None:
    """Multi-tagged images: removing one tag only untags, so bytes_freed must be 0.

    Without this guard the admin-facing 'space reclaimed' number lies whenever
    the same image is referenced by more than one tag. We assert that until the
    *last* tag is removed, HaRP reports bytes_freed=0; the final removal then
    reports the real reclaimed size.
    """
    print("scenario 5: multi-tag image reports bytes_freed=0 until last tag removed")
    base = "alpine:3.21.0"
    tag_a = "multitag-test-a:latest"
    tag_b = "multitag-test-b:latest"

    run(["docker", "pull", base], stdout=DEVNULL, stderr=DEVNULL, check=True)
    try:
        run(["docker", "tag", base, tag_a], stdout=DEVNULL, stderr=DEVNULL, check=True)
        run(["docker", "tag", base, tag_b], stdout=DEVNULL, stderr=DEVNULL, check=True)

        # Untag tag_a. base + tag_b still reference the image -> nothing freed.
        result = harp_image_remove(tag_a)
        assert result.get("deleted") is True, f"Expected deleted=true, got {result}"
        assert result.get("bytes_freed") == 0, (
            f"Expected bytes_freed=0 for multi-tag untag, got {result}. "
            "HaRP must distinguish Docker 'Untagged' from 'Deleted' operations."
        )
        assert image_present("alpine:3.21.0"), "Underlying image should still be on disk"

        # Untag tag_b. base still references the image -> still nothing freed.
        result = harp_image_remove(tag_b)
        assert result.get("deleted") is True
        assert result.get("bytes_freed") == 0, f"Expected bytes_freed=0, got {result}"
        assert image_present("alpine:3.21.0")

        # Final tag removal -> image actually deleted, real bytes_freed reported.
        result = harp_image_remove(base)
        assert result.get("deleted") is True
        assert result.get("bytes_freed", 0) > 0, (
            f"Expected bytes_freed>0 after last tag removal, got {result}"
        )
        assert not image_present("alpine:3.21.0"), "Image should be gone after last tag removal"
    finally:
        for ref in (tag_a, tag_b, base):
            run(["docker", "rmi", "-f", ref], stdout=DEVNULL, stderr=DEVNULL, check=False)


def main() -> int:
    # Make scenarios deterministic: zero grace so queued jobs are eligible immediately.
    occ("config:app:set", "app_api", "image_cleanup_grace_hours", "--value=0", "--type=integer")
    occ("config:app:set", "app_api", "image_cleanup_enabled", "--value=true", "--type=boolean")

    try:
        reset_state()
        scenario_default_uninstall_runs_via_queued_job()

        reset_state()
        scenario_purge_now_deletes_immediately()

        reset_state()
        scenario_keep_image_skips_cleanup()

        reset_state()
        scenario_reinstall_before_cron_keeps_image()

        reset_state()
        scenario_multi_tag_reports_zero_until_last_tag_removed()
    finally:
        reset_state()
        # Restore the default grace so we don't surprise the next test.
        occ("config:app:delete", "app_api", "image_cleanup_grace_hours", check=False)
        occ("config:app:delete", "app_api", "image_cleanup_enabled", check=False)

    print("\nAll image cleanup scenarios passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
