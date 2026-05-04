#!/bin/bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Register and enable the integration-test ExApp via OCC against the live
# Nextcloud container. Idempotent: re-running unregisters first.
#
# Required env: APP_SECRET (32+ char random string).
# Optional env: NEXTCLOUD_CONTAINER (default appapi-nextcloud-1),
#               DAEMON_NAME       (default manual_daemon),
#               APP_ID            (default test_appapi),
#               APP_VERSION       (default 1.0.0),
#               APP_PORT          (default 9009),
#               APP_HOST          (default host.docker.internal — host as seen
#                                  from inside the Nextcloud container).
#
# The ExApp itself (uvicorn _test_app:APP) must be running and reachable from
# the Nextcloud container at $APP_HOST:$APP_PORT BEFORE this script runs,
# because `app_api:app:register --wait-finish` calls /heartbeat and /init
# synchronously and would fail/hang otherwise.
set -euo pipefail

NEXTCLOUD_CONTAINER=${NEXTCLOUD_CONTAINER:-appapi-nextcloud-1}
DAEMON_NAME=${DAEMON_NAME:-manual_daemon}
APP_ID=${APP_ID:-test_appapi}
APP_VERSION=${APP_VERSION:-1.0.0}
APP_PORT=${APP_PORT:-9009}
APP_HOST=${APP_HOST:-host.docker.internal}

if [[ -z "${APP_SECRET:-}" ]]; then
  echo "APP_SECRET must be set (32+ random chars)" >&2
  exit 2
fi

OCC=(docker exec "$NEXTCLOUD_CONTAINER" sudo -u www-data php occ)

# Wait for /heartbeat (max 30s)
for _ in $(seq 1 30); do
  if docker exec "$NEXTCLOUD_CONTAINER" curl -fs "http://$APP_HOST:$APP_PORT/heartbeat" >/dev/null; then
    break
  fi
  sleep 1
done
docker exec "$NEXTCLOUD_CONTAINER" curl -fs "http://$APP_HOST:$APP_PORT/heartbeat" >/dev/null \
  || { echo "Test ExApp /heartbeat unreachable at $APP_HOST:$APP_PORT" >&2; exit 1; }

# Best-effort unregister so the script is idempotent.
"${OCC[@]}" app_api:app:unregister "$APP_ID" --silent 2>/dev/null || true

JSON=$(cat <<EOF
{"id":"$APP_ID","name":"AppAPI Integration Test ExApp","version":"$APP_VERSION","secret":"$APP_SECRET","port":$APP_PORT,"host":"$APP_HOST","protocol":"http"}
EOF
)
"${OCC[@]}" app_api:app:register "$APP_ID" "$DAEMON_NAME" --json-info="$JSON" --silent --wait-finish
"${OCC[@]}" app_api:app:enable "$APP_ID" || true

# Confirm
"${OCC[@]}" app_api:app:list | grep -q "^$APP_ID .* \[enabled\]$" \
  || { echo "ExApp not in enabled state after register" >&2; exit 1; }

echo "Registered & enabled: $APP_ID (secret=${APP_SECRET:0:6}…)"
