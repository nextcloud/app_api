# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Browser test for ExApp-registered FileActionMenu entries.

Regression for https://github.com/nextcloud/app_api/issues/848: an ExApp's
FileActionMenu V2 entry is registered server-side (DB row exists, OCS GET
returns it) but does not appear in the Files app dropdown because AppAPI's
bundled @nextcloud/files version stored actions in a registry the NC server's
Files app no longer reads.

The test registers a file action via OCS, uploads a matching test file, and
drives a real browser through the Files UI to assert the entry is visible.
"""

from __future__ import annotations

import requests
from playwright.sync_api import Page, expect

from ._client import AppAPIClient

ACTION_NAME = "test_appapi_fileaction"
ACTION_DISPLAY = "AppAPI Integration FileAction"
TEST_FILE_NAME = "test_appapi_fileaction.txt"


def _ocs_register_file_action(client: AppAPIClient) -> None:
    body = {
        "name": ACTION_NAME,
        "displayName": ACTION_DISPLAY,
        "actionHandler": "/file-action",
        "icon": "",
        # mime is matched by substring against node mime; "text" hits text/plain.
        "mime": "text",
        "permissions": 31,
        "order": 0,
    }
    r = client.request(
        "POST",
        "/ocs/v1.php/apps/app_api/api/v2/ui/files-actions-menu",
        json=body,
    )
    assert r.status_code == 200, f"register OCS call failed: {r.status_code} {r.text}"


def _ocs_unregister_file_action(client: AppAPIClient) -> None:
    client.request(
        "DELETE",
        "/ocs/v1.php/apps/app_api/api/v1/ui/files-actions-menu",
        json={"name": ACTION_NAME},
    )


def _webdav_put(nextcloud_url: str, user: str, password: str, name: str, content: bytes) -> None:
    r = requests.put(
        f"{nextcloud_url}/remote.php/dav/files/{user}/{name}",
        auth=(user, password),
        data=content,
        headers={"OCS-APIRequest": "true"},
        timeout=30,
    )
    assert r.status_code in (201, 204), f"WebDAV PUT failed: {r.status_code} {r.text}"


def _webdav_delete(nextcloud_url: str, user: str, password: str, name: str) -> None:
    requests.delete(
        f"{nextcloud_url}/remote.php/dav/files/{user}/{name}",
        auth=(user, password),
        headers={"OCS-APIRequest": "true"},
        timeout=30,
    )


def test_file_action_visible_in_files_menu(
    client: AppAPIClient,
    logged_in_page: Page,
    nextcloud_url: str,
    admin_credentials: tuple[str, str],
) -> None:
    user, password = admin_credentials

    _ocs_register_file_action(client)
    _webdav_put(nextcloud_url, user, password, TEST_FILE_NAME, b"hello 848")
    try:
        page = logged_in_page
        page.goto(f"{nextcloud_url}/index.php/apps/files/files")

        # The Files app renders rows lazily (virtualised list). Wait for the
        # specific row keyed by the data-cy attribute to land in the DOM
        # before clicking on its Actions button.
        file_row = page.locator(
            f'[data-cy-files-list-row-name="{TEST_FILE_NAME}"]'
        ).first
        file_row.wait_for(state="visible", timeout=15_000)
        file_row.get_by_role("button", name="Actions").click()

        expect(
            page.get_by_role("menuitem", name=ACTION_DISPLAY)
        ).to_be_visible(timeout=5_000)
    finally:
        _webdav_delete(nextcloud_url, user, password, TEST_FILE_NAME)
        _ocs_unregister_file_action(client)
