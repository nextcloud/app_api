# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""AA-USER-ID header round-trip through AppAPI's auth middleware.

Replaces nc_py_api/tests/actual_tests/nc_app_test.py::test_change_user_async.
We send a request to a Nextcloud OCS endpoint that returns the current user
ID (`/cloud/user`), once with no AA-USER-ID and once with one explicitly set.
"""

import os
import subprocess

from ._client import AppAPIClient

OCC = os.environ.get(
    "OCC_CMD",
    "docker exec appapi-nextcloud-1 sudo -u www-data php occ",
).split()


def _whoami(client: AppAPIClient) -> str:
    r = client.request(
        "GET", "/ocs/v1.php/cloud/user",
        headers={"OCS-APIRequest": "true"},
    )
    assert r.status_code == 200, r.text
    payload = r.json()["ocs"]
    assert payload["meta"]["statuscode"] == 100, payload["meta"]
    return payload["data"]["id"]


def test_default_user_is_admin(client: AppAPIClient) -> None:
    assert _whoami(client) == "admin"


def test_aa_user_id_changes_effective_user(client: AppAPIClient) -> None:
    """When the ExApp passes AA-USER-ID, AppAPI's auth middleware impersonates
    that user for the duration of the request — Nextcloud's /cloud/user then
    reports the impersonated user, not admin."""
    target_user = _ensure_test_user_exists()
    impersonated = AppAPIClient(
        base_url=client.base_url, app_id=client.app_id,
        app_secret=client.app_secret, app_version=client.app_version,
        user=target_user,
    )
    assert _whoami(impersonated) == target_user


def _ensure_test_user_exists() -> str:
    """Make sure a non-admin user exists for the impersonation check."""
    user = "phpunit_user_impersonation"
    password = "phpunit_password_for_test"
    if subprocess.run(OCC + ["user:info", user], capture_output=True).returncode == 0:
        return user
    # `user:add --password-from-env` reads OC_PASS from the env. For a
    # `docker exec ... sudo` OCC we have to inject via `-e` and preserve via
    # `sudo -E`; for a plain `php occ` OCC, subprocess env= is enough.
    if OCC[:2] == ["docker", "exec"]:
        cmd = ["docker", "exec", "-e", f"OC_PASS={password}"] + OCC[2:]
        if "-u" in cmd and "-E" not in cmd:
            cmd.insert(cmd.index("-u") + 2, "-E")
        subprocess.run(cmd + ["user:add", "--password-from-env", user],
                       check=True, capture_output=True)
    else:
        subprocess.run(
            OCC + ["user:add", "--password-from-env", user],
            env={**os.environ, "OC_PASS": password},
            check=True, capture_output=True,
        )
    return user
