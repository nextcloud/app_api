# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Minimal ExApp written against the raw AppAPI contract: Python stdlib only, no frameworks.

This file is the whole application. It shows everything an ExApp must do, in any language:

1. Serve HTTP where AppAPI expects it:
   - unix socket /tmp/exapp.sock when HP_SHARED_KEY is set (docker-install via HaRP; the
     frpc started by start.sh tunnels HaRP:APP_PORT to this socket; the vendored start.sh
     pins that exact path),
   - APP_HOST:APP_PORT otherwise (manual-install for development).
2. Answer GET /heartbeat with {"status": "ok"} (no auth; AppAPI polls it during install).
3. Answer PUT /enabled?enabled=1|0 with {"error": ""} (auth required).
4. Optionally implement POST /init: return immediately, then report progress 0..100 to
   Nextcloud (PUT .../ocs/v1.php/apps/app_api/ex-app/status). Without an /init route,
   AppAPI treats 404 as "no init needed". This app implements it to show the round trip.
5. Validate the three auth headers on every non-infrastructure request.
6. Call Nextcloud OCS APIs with the ExApp auth headers (see /nc-version).

Port this file to your language of choice; the contract is only what you see here.
"""
import base64
import hmac
import json
import os
import socket
import socketserver
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

APP_ID = os.environ["APP_ID"]
APP_VERSION = os.environ.get("APP_VERSION", "1.0.0")
APP_SECRET = os.environ["APP_SECRET"]
NEXTCLOUD_URL = os.environ["NEXTCLOUD_URL"]

# Never log credentials: requests arrive with the app auth header, the daemon's HaRP
# shared key, and (for browser traffic) the visitor's Nextcloud session cookies.
REDACTED_HEADERS = {"authorization-app-api", "harp-shared-key", "authorization", "cookie"}


def log(msg):
    sys.stderr.write(msg + "\n")
    sys.stderr.flush()


def nc_headers(user=""):
    """Headers for calling Nextcloud as this ExApp, optionally impersonating a user."""
    auth = base64.b64encode(f"{user}:{APP_SECRET}".encode()).decode()
    return {
        "EX-APP-ID": APP_ID,
        "EX-APP-VERSION": APP_VERSION,
        "AUTHORIZATION-APP-API": auth,
        "OCS-APIRequest": "true",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def nc_request(method, path, body=None, user=""):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(NEXTCLOUD_URL + path, data=data, method=method, headers=nc_headers(user))
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode() or "{}")


def run_init_steps():
    """Fake initialization: report progress so the admin UI shows the app initializing."""
    for progress in (25, 50, 75, 100):
        time.sleep(1)
        try:
            nc_request("PUT", "/ocs/v1.php/apps/app_api/ex-app/status", {"progress": progress, "error": ""})
            log("init progress reported: %d" % progress)
        except Exception as e:
            log("init progress report failed: %s" % e)
            return


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        headers = {k: ("<redacted>" if k.lower() in REDACTED_HEADERS else v) for k, v in self.headers.items()}
        log("REQ %s %s %s" % (self.command, self.path, json.dumps(headers)))

    def _drain_body(self):
        # With keep-alive, an unread request body would desynchronize the next request.
        length = int(self.headers.get("Content-Length", 0) or 0)
        if length:
            self.rfile.read(length)

    def _reply(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _auth_user(self):
        """Validate the mandatory AppAPI headers; returns (authenticated, acting_user)."""
        ex_app_id = self.headers.get("EX-APP-ID", "")
        ex_app_version = self.headers.get("EX-APP-VERSION", "")
        auth = self.headers.get("AUTHORIZATION-APP-API", "")
        if not ex_app_id or not ex_app_version or not auth or ex_app_id != APP_ID:
            return False, None
        try:
            user, secret = base64.b64decode(auth, validate=True).decode("UTF-8").split(":", 1)
        except Exception:
            return False, None
        return hmac.compare_digest(secret, APP_SECRET), user

    def do_GET(self):
        path = self.path.split("?")[0]
        if path == "/heartbeat":
            return self._reply(200, {"status": "ok"})
        authed, user = self._auth_user()
        if not authed:
            return self._reply(401, {"error": "unauthorized"})
        if path == "/echo":
            return self._reply(200, {"app_id": APP_ID, "app_version": APP_VERSION, "user": user})
        if path == "/nc-version":
            try:
                caps = nc_request("GET", "/ocs/v2.php/cloud/capabilities?format=json")
            except Exception as e:
                return self._reply(502, {"error": "capabilities request failed: %s" % e})
            version = caps.get("ocs", {}).get("data", {}).get("version", {}).get("string", "unknown")
            return self._reply(200, {"nextcloud_version": version})
        return self._reply(404, {"error": "not found"})

    def do_PUT(self):
        self._drain_body()
        path = self.path.split("?")[0]
        authed, _ = self._auth_user()
        if not authed:
            return self._reply(401, {"error": "unauthorized"})
        if path == "/enabled":
            enabled = "enabled=1" in self.path
            log("enabled state set to %s" % enabled)
            return self._reply(200, {"error": ""})
        return self._reply(404, {"error": "not found"})

    def do_POST(self):
        self._drain_body()
        path = self.path.split("?")[0]
        authed, _ = self._auth_user()
        if not authed:
            return self._reply(401, {"error": "unauthorized"})
        if path == "/init":
            threading.Thread(target=run_init_steps, daemon=True).start()
            return self._reply(200, {})
        return self._reply(404, {"error": "not found"})


class UnixHTTPServer(ThreadingHTTPServer):
    address_family = socket.AF_UNIX

    def server_bind(self):
        socketserver.TCPServer.server_bind(self)
        self.server_name = "exapp"
        self.server_port = 0

    def get_request(self):
        request, _ = self.socket.accept()
        return request, ("unix-socket", 0)


def main():
    if os.environ.get("HP_SHARED_KEY"):
        sock_path = "/tmp/exapp.sock"  # fixed: the vendored start.sh pins this path in frpc.toml
        try:
            os.unlink(sock_path)
        except FileNotFoundError:
            pass
        server = UnixHTTPServer(sock_path, Handler)
        os.chmod(sock_path, 0o666)
        log("listening on unix socket %s (HaRP mode)" % sock_path)
    else:
        host = os.environ.get("APP_HOST", "0.0.0.0") or "0.0.0.0"
        port = int(os.environ["APP_PORT"])
        server = ThreadingHTTPServer((host, port), Handler)
        log("listening on %s:%d (manual/direct mode)" % (host, port))
    server.serve_forever()


if __name__ == "__main__":
    main()
