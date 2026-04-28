# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pytest fixtures for AppAPI integration tests.

These tests assume:
  1. A Nextcloud instance reachable at NEXTCLOUD_URL.
  2. The test ExApp (`_test_app.py`) is running on TEST_APP_HOST:TEST_APP_PORT.
  3. The ExApp has been registered & enabled through OCC against the
     manual_daemon (see register_test_exapp.sh).

In the dev VM all three are arranged before running pytest. See README.md.
"""

from __future__ import annotations

import os
import subprocess

import pytest

from ._client import AppAPIClient

NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://nextcloud.appapi")
APP_ID = os.environ.get("APP_ID", "test_appapi")
APP_VERSION = os.environ.get("APP_VERSION", "1.0.0")
APP_SECRET = os.environ["APP_SECRET"]
TEST_APP_URL = os.environ.get("TEST_APP_URL", "http://127.0.0.1:9009")

OCC = ["docker", "exec", "appapi-nextcloud-1",
       "sudo", "-u", "www-data", "php", "occ"]


@pytest.fixture(scope="session")
def client() -> AppAPIClient:
    return AppAPIClient(
        base_url=NEXTCLOUD_URL, app_id=APP_ID,
        app_secret=APP_SECRET, app_version=APP_VERSION,
    )


@pytest.fixture(scope="session")
def app_id() -> str:
    return APP_ID


@pytest.fixture(scope="session")
def app_version() -> str:
    return APP_VERSION


@pytest.fixture(scope="session")
def app_secret() -> str:
    return APP_SECRET


@pytest.fixture(scope="session")
def test_app_url() -> str:
    return TEST_APP_URL


@pytest.fixture(scope="session", autouse=True)
def _ensure_test_app_registered() -> None:
    r = subprocess.run(OCC + ["app_api:app:list"], capture_output=True, text=True, check=True)
    if APP_ID not in r.stdout:
        raise RuntimeError(
            f"ExApp '{APP_ID}' not registered. Run register_test_exapp.sh first."
        )
    if "[enabled]" not in next((line for line in r.stdout.splitlines() if APP_ID in line), ""):
        raise RuntimeError(f"ExApp '{APP_ID}' is registered but not enabled.")
