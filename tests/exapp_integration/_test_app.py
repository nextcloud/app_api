# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Minimal test ExApp for AppAPI integration tests.

Replaces the nc_py_api-based `tests/_install.py`. Implements only the
callbacks AppAPI invokes (`/enabled`, `/heartbeat`) plus a few introspection
endpoints used by the integration tests.

We deliberately do not register a `/init` route: AppAPI's
`dispatchExAppInitInternal` treats a 404/501 from the ExApp as "no init step
needed" and marks init progress = 100. This lets `app_api:app:register
--wait-finish` complete promptly without the test app having to call back to
`/ex-app/status` itself.
"""

import os
from base64 import b64decode

from fastapi import FastAPI, HTTPException, Request as FastAPIRequest
from fastapi.responses import JSONResponse

APP_ID = os.environ["APP_ID"]
APP_SECRET = os.environ["APP_SECRET"]
APP_VERSION = os.environ.get("APP_VERSION", "1.0.0")

APP = FastAPI()


def verify_auth(request: FastAPIRequest) -> str:
    """Validate AppAPI auth headers. Returns the username on success."""
    ex_app_id = request.headers.get("EX-APP-ID", "")
    ex_app_version = request.headers.get("EX-APP-VERSION", "")
    auth_app_api = request.headers.get("AUTHORIZATION-APP-API", "")

    if not ex_app_id or not ex_app_version or not auth_app_api:
        raise HTTPException(status_code=401, detail="Missing AppAPI headers")
    if ex_app_id != APP_ID:
        raise HTTPException(status_code=401, detail=f"Invalid EX-APP-ID: {ex_app_id}")
    try:
        decoded = b64decode(auth_app_api).decode("UTF-8")
        username, secret = decoded.split(":", maxsplit=1)
    except Exception:
        raise HTTPException(status_code=401, detail="Malformed AUTHORIZATION-APP-API")
    if secret != APP_SECRET:
        raise HTTPException(status_code=401, detail="Invalid app secret")
    return username


@APP.put("/enabled")
async def enabled_callback(enabled: bool, request: FastAPIRequest):
    verify_auth(request)
    return JSONResponse(content={"error": ""}, status_code=200)


@APP.get("/heartbeat")
async def heartbeat_callback():
    return JSONResponse(content={"status": "ok"}, status_code=200)


@APP.get("/cfg-echo")
async def cfg_echo(request: FastAPIRequest):
    """Echo the env contract back. Used by test_app_cfg.py."""
    verify_auth(request)
    return {
        "app_id": APP_ID,
        "app_version": APP_VERSION,
        "app_secret_prefix": APP_SECRET[:6],
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "_test_app:APP",
        host=os.environ.get("APP_HOST", "0.0.0.0"),
        port=int(os.environ.get("APP_PORT", "9009")),
        log_level="info",
    )
