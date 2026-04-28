# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Auth contract tests against AppAPI's middleware.

Replaces nc_py_api/tests/_app_security_checks.py. We pick a real
AppAPI-protected endpoint (`/api/v1/ui/top-menu`) and exercise the four header
permutations that nc_py_api covered.
"""

import requests

from ._client import AppAPIClient

PROTECTED_PATH = "/ocs/v1.php/apps/app_api/api/v1/ui/top-menu"


def _get(client: AppAPIClient, **headers_override) -> requests.Response:
    return client.request("GET", PROTECTED_PATH,
                          params={"name": "test_security_probe"},
                          headers=headers_override)


def test_valid_headers_succeed(client: AppAPIClient) -> None:
    """With proper auth, the endpoint reaches the controller. The probe row
    does not exist, so we get OCS 404 — but HTTP is 200 and the OCS auth
    layer (statuscode 997) is NOT triggered."""
    r = _get(client)
    assert r.status_code == 200
    assert r.json()["ocs"]["meta"]["statuscode"] != 997


def test_missing_authorization_header(client: AppAPIClient) -> None:
    r = _get(client, **{"AUTHORIZATION-APP-API": None})
    assert r.status_code == 401
    assert r.json()["ocs"]["meta"]["statuscode"] == 997


def test_missing_ex_app_id_header(client: AppAPIClient) -> None:
    r = _get(client, **{"EX-APP-ID": None})
    assert r.status_code == 401


def test_wrong_app_secret(client: AppAPIClient, app_id: str) -> None:
    bad = AppAPIClient(
        base_url=client.base_url, app_id=app_id,
        app_secret="this_is_definitely_not_the_secret",
        app_version=client.app_version,
    )
    r = bad.request("GET", PROTECTED_PATH, params={"name": "x"})
    assert r.status_code == 401
    assert r.json()["ocs"]["meta"]["statuscode"] == 997


def test_unknown_app_id(client: AppAPIClient) -> None:
    bad = AppAPIClient(
        base_url=client.base_url,
        app_id="phpunit_does_not_exist_zzz",
        app_secret=client.app_secret,
        app_version=client.app_version,
    )
    r = bad.request("GET", PROTECTED_PATH, params={"name": "x"})
    assert r.status_code == 401
