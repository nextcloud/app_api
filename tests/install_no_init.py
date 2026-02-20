#
# SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import json
import os
from base64 import b64decode, b64encode
from urllib.request import Request, urlopen

from fastapi import FastAPI, HTTPException, Request as FastAPIRequest
from fastapi.responses import JSONResponse

APP_ID = os.environ["APP_ID"]
APP_SECRET = os.environ["APP_SECRET"]
APP_VERSION = os.environ.get("APP_VERSION", "1.0.0")
NEXTCLOUD_URL = os.environ.get("NEXTCLOUD_URL", "http://localhost:8080").rstrip("/")

APP = FastAPI()


def verify_auth(request: FastAPIRequest) -> str:
    """Validate AppAPI auth headers. Returns the username on success."""
    ex_app_id = request.headers.get("EX-APP-ID", "")
    ex_app_version = request.headers.get("EX-APP-VERSION", "")
    auth_app_api = request.headers.get("AUTHORIZATION-APP-API", "")

    missing = [h for h, v in [
        ("EX-APP-ID", ex_app_id),
        ("EX-APP-VERSION", ex_app_version),
        ("AUTHORIZATION-APP-API", auth_app_api),
    ] if not v]
    if missing:
        raise HTTPException(status_code=401, detail=f"Missing headers: {missing}")

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


def log_to_nextcloud(username: str, level: int, message: str) -> None:
    """Send a log entry to Nextcloud via OCS API."""
    url = f"{NEXTCLOUD_URL}/ocs/v1.php/apps/app_api/api/v1/log"
    data = json.dumps({"level": level, "message": message}).encode("UTF-8")
    auth_header = b64encode(f"{username}:{APP_SECRET}".encode("UTF-8")).decode("ASCII")
    req = Request(url, data=data, method="POST", headers={
        "Content-Type": "application/json",
        "OCS-APIRequest": "true",
        "EX-APP-ID": APP_ID,
        "EX-APP-VERSION": APP_VERSION,
        "AA-VERSION": "2.0.0",
        "AUTHORIZATION-APP-API": auth_header,
    })
    try:
        urlopen(req)
    except Exception as e:
        print(f"[test-app] Failed to log to Nextcloud: {e}")


@APP.put("/enabled")
async def enabled_callback(enabled: bool, request: FastAPIRequest):
    username = verify_auth(request)
    if enabled:
        log_to_nextcloud(username, 2, f"Hello from {APP_ID} :)")
    else:
        log_to_nextcloud(username, 2, f"Bye bye from {APP_ID} :(")
    return JSONResponse(content={"error": ""}, status_code=200)


@APP.get("/heartbeat")
async def heartbeat_callback():
    return JSONResponse(content={"status": "ok"}, status_code=200)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "install_no_init:APP",
        host=os.environ.get("APP_HOST", "127.0.0.1"),
        port=int(os.environ["APP_PORT"]),
        log_level="trace",
    )
