# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Lifecycle callbacks: /init and /enabled fire on the registered ExApp.

Replaces the implicit lifecycle coverage from nc_py_api/tests/_install.py and
its `set_handlers` indirection. We use AppAPI's /ex-app/{appId}/enabled OCS
endpoint to flip enabled state and verify the test ExApp returned 200 to the
callback (otherwise the OCC enable command itself would fail).
"""

import subprocess

OCC = ["docker", "exec", "appapi-nextcloud-1",
       "sudo", "-u", "www-data", "php", "occ"]


def _is_enabled(app_id: str) -> bool:
    r = subprocess.run(OCC + ["app_api:app:list"], capture_output=True, text=True, check=True)
    line = next((ln for ln in r.stdout.splitlines() if app_id in ln), "")
    return "[enabled]" in line


def test_disable_then_reenable_calls_callbacks(app_id: str) -> None:
    """Toggle enabled state via OCC and confirm AppAPI invoked the ExApp's
    /enabled callback (the OCC command would error out non-zero if the
    callback returned anything other than 200)."""
    assert _is_enabled(app_id), "test fixture must start enabled"

    try:
        r = subprocess.run(OCC + ["app_api:app:disable", app_id], capture_output=True, text=True)
        assert r.returncode == 0, f"disable failed: stdout={r.stdout!r} stderr={r.stderr!r}"
        assert not _is_enabled(app_id)

        r = subprocess.run(OCC + ["app_api:app:enable", app_id], capture_output=True, text=True)
        assert r.returncode == 0, f"enable failed: stdout={r.stdout!r} stderr={r.stderr!r}"
        assert _is_enabled(app_id)
    finally:
        # Make sure we leave the fixture enabled even on test failure.
        if not _is_enabled(app_id):
            subprocess.run(OCC + ["app_api:app:enable", app_id], check=False)
