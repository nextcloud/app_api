# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Alpine 3.21 ships an frp package, which start.sh needs for HaRP deployments
# (see https://github.com/nextcloud/HaRP "Adapting ExApps to use HaRP").
FROM alpine:3.21
RUN apk add --no-cache bash curl frp python3

COPY --chmod=755 start.sh /start.sh
COPY main.py /main.py

# Works in both modes: unix socket (HaRP) and TCP (direct). The start-interval matters:
# AppAPI waits for the container to become healthy during install, so a slow first probe
# directly delays every deploy.
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=10s --start-interval=1s \
    CMD sh -c 'curl -sf --unix-socket /tmp/exapp.sock http://localhost/heartbeat || curl -sf "http://127.0.0.1:${APP_PORT}/heartbeat"'

ENTRYPOINT ["/start.sh", "python3", "/main.py"]
