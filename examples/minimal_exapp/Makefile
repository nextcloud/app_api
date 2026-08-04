# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Development helpers for the minimal ExApp. Override any variable on the command line,
# e.g. `make NEXTCLOUD_CONTAINER=master-nextcloud-1 register-docker`.

APP_ID            := minimal_exapp
VERSION           := $(shell sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml | head -1)
IMAGE_TAG         := $(shell sed -n 's/.*<image-tag>\(.*\)<\/image-tag>.*/\1/p' appinfo/info.xml | head -1)
IMAGE             := example.local/minimal_exapp

NEXTCLOUD_CONTAINER ?= master-nextcloud-1
OCC                  = docker exec $(NEXTCLOUD_CONTAINER) sudo -u www-data php occ

# Daemon names as registered in docs/appapi/dev-environment.md
DAEMON_DOCKER ?= local-harp
DAEMON_MANUAL ?= manual_dev

# Fast-loop settings. The default secret is a deliberately dummy dev-only value; it must stay
# identical between `make run` and `make register-manual`, which is why it is not generated.
# Override it for anything beyond a throwaway dev environment.
APP_PORT   ?= 9080
APP_SECRET ?= dev-only-secret-minimal-exapp-0123456789

JSON_INFO = {"id":"$(APP_ID)","name":"Minimal ExApp","version":"$(VERSION)","secret":"$(APP_SECRET)","port":$(APP_PORT),"routes":[{"url":"^\\/echo$$","verb":"GET","access_level":0},{"url":"^\\/nc-version$$","verb":"GET","access_level":0}]}

.PHONY: run register-manual build register-docker update-docker unregister logs

run:
	APP_ID=$(APP_ID) APP_VERSION=$(VERSION) APP_SECRET=$(APP_SECRET) APP_PORT=$(APP_PORT) \
	NEXTCLOUD_URL=$${NEXTCLOUD_URL:-http://nextcloud.local} python3 main.py

register-manual:
	$(OCC) app_api:app:unregister $(APP_ID) --silent || true
	$(OCC) app_api:app:register $(APP_ID) $(DAEMON_MANUAL) --json-info '$(JSON_INFO)' --wait-finish

build:
	@test "$(VERSION)" = "$(IMAGE_TAG)" || { echo "info.xml <version> ($(VERSION)) and <image-tag> ($(IMAGE_TAG)) differ; bump both, AppAPI deploys <image-tag>"; exit 1; }
	docker build -t $(IMAGE):$(IMAGE_TAG) .

register-docker:
	$(OCC) app_api:daemon:registry:add $(DAEMON_DOCKER) --registry-from example.local --registry-to local || true
	docker cp appinfo/info.xml $(NEXTCLOUD_CONTAINER):/tmp/$(APP_ID)-info.xml
	$(OCC) app_api:app:unregister $(APP_ID) --silent || true
	$(OCC) app_api:app:register $(APP_ID) $(DAEMON_DOCKER) --info-xml /tmp/$(APP_ID)-info.xml --wait-finish

update-docker:
	docker cp appinfo/info.xml $(NEXTCLOUD_CONTAINER):/tmp/$(APP_ID)-info.xml
	$(OCC) app_api:app:update $(APP_ID) --info-xml /tmp/$(APP_ID)-info.xml --wait-finish

unregister:
	$(OCC) app_api:app:unregister $(APP_ID)

logs:
	docker logs -f nc_app_$(APP_ID)
