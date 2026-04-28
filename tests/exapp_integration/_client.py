# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Tiny HTTP client wrapping AppAPI's auth contract.

Replaces the parts of nc_py_api that the integration tests actually used:
the AppAPI auth headers and a `requests`-style call.

No HMAC / request signing — AppAPI accepts the simple base64 auth header for
ExApp -> Nextcloud calls. See `tests/install_no_init.py` (the existing in-tree
test ExApp), which hits AppAPI's /log endpoint with these same headers.
"""

from __future__ import annotations

from base64 import b64encode
from dataclasses import dataclass, field
from typing import Any

import requests


@dataclass
class AppAPIClient:
    base_url: str
    app_id: str
    app_secret: str
    app_version: str = "1.0.0"
    user: str = "admin"
    extra_headers: dict[str, str] = field(default_factory=dict)
    timeout: float = 30.0

    def auth_headers(self) -> dict[str, str]:
        basic = b64encode(f"{self.user}:{self.app_secret}".encode()).decode()
        h = {
            "EX-APP-ID": self.app_id,
            "EX-APP-VERSION": self.app_version,
            "AUTHORIZATION-APP-API": basic,
            "AA-VERSION": "2.0.0",
            "OCS-APIRequest": "true",
            "Accept": "application/json",
        }
        if self.user != "admin":
            h["AA-USER-ID"] = self.user
        h.update(self.extra_headers)
        return h

    def request(self, method: str, path: str, **kwargs: Any) -> requests.Response:
        """Hit `<base_url><path>` with AppAPI auth headers.

        Caller-supplied `headers` win over the defaults. Pass `headers={"X": None}`
        to drop a default header (used in negative-auth tests).
        """
        headers = self.auth_headers()
        for k, v in (kwargs.pop("headers", None) or {}).items():
            if v is None:
                headers.pop(k, None)
            else:
                headers[k] = v
        kwargs.setdefault("timeout", self.timeout)
        return requests.request(method, f"{self.base_url}{path}", headers=headers, **kwargs)
