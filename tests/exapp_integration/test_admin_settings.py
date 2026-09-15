# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Browser test for the AppAPI admin settings page.

Regression for https://github.com/nextcloud/app_api/pull/1034: on stable32 a
dependency bump paired the bundled @nextcloud/vue 8.17.1 with @nextcloud/l10n
3.4.1, so the admin settings script threw "Cannot read properties of undefined
(reading 'catalogs')" while loading. The page stayed empty without any server
error, so the test checks both the rendered content and the absence of errors.
"""

from __future__ import annotations

import os

from playwright.sync_api import Page, Response, expect

DAEMON_NAME = os.environ.get("DAEMON_NAME", "manual_daemon")


def test_admin_settings_page_renders(logged_in_page: Page, nextcloud_url: str) -> None:
    page = logged_in_page
    errors: list[str] = []
    page.on("pageerror", lambda error: errors.append(f"uncaught: {error.message}"))

    def on_response(response: Response) -> None:
        if response.status >= 400 and "/apps/app_api/" in response.url:
            errors.append(f"{response.status} {response.url}")

    page.on("response", on_response)

    page.goto(f"{nextcloud_url}/index.php/settings/admin/app_api")
    # goto() waits for the load event, so an error thrown while the bundle loads is recorded by now
    assert errors == []

    settings = page.locator("#app_api_settings")
    expect(settings.get_by_role("heading", name="AppAPI", exact=True)).to_be_visible()
    expect(settings.get_by_role("heading", name="Deploy daemons")).to_be_visible()
    expect(
        settings.get_by_role("list", name="Registered Deploy daemons list")
    ).to_contain_text(DAEMON_NAME)
    expect(settings.get_by_role("button", name="Register daemon")).to_be_visible()
    assert errors == []
