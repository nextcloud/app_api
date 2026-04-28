# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Env contract: the running ExApp received APP_ID/APP_VERSION/APP_SECRET.

Replaces nc_py_api/tests/actual_tests/nc_app_test.py::test_app_cfg.

We do not assert from the AppAPI side — the contract is "the deploy daemon
populated these env vars in the ExApp container". Test by asking the test
ExApp to echo them back.
"""

import requests


def test_test_app_received_env(test_app_url: str, app_id: str, app_version: str, app_secret: str) -> None:
    # Use the same simple base64 auth the ExApp validates incoming requests with.
    from base64 import b64encode
    auth = b64encode(f"admin:{app_secret}".encode()).decode()
    r = requests.get(
        f"{test_app_url}/cfg-echo",
        headers={
            "EX-APP-ID": app_id,
            "EX-APP-VERSION": app_version,
            "AUTHORIZATION-APP-API": auth,
        },
        timeout=10,
    )
    assert r.status_code == 200
    body = r.json()
    assert body["app_id"] == app_id
    assert body["app_version"] == app_version
    # Don't transmit/log the full secret; first 6 chars is enough to prove
    # the same value was injected.
    assert body["app_secret_prefix"] == app_secret[:6]
