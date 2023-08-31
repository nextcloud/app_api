.DEFAULT_GOAL := help

.PHONY: help
help:
	@echo "Welcome to ToGif example. Please use \`make <target>\` where <target> is one of"
	@echo " "
	@echo "  Next commands are only for dev environment with nextcloud-docker-dev!"
	@echo "  They should run from the host you are developing on(with activated venv) and not in the container with Nextcloud!"
	@echo "  "
	@echo "  build-push        build image and upload to ghcr.io"
	@echo "  "
	@echo "  deploy            deploy example to registered 'docker_dev'"
	@echo "  "
	@echo "  run27             install ToGif for Nextcloud 27"
	@echo "  "
	@echo "  manual_register27 perform registration of running 'to_gif' into the 'manual_install' deploy daemon."

.PHONY: build-push
build-push:
	docker login ghcr.io
	docker buildx build --push --platform linux/arm64/v8,linux/amd64 --tag ghcr.io/cloud-py-api/to_gif__python:latest .

.PHONY: deploy
deploy:
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:deploy to_gif docker_dev \
		--info-xml https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/examples/to_gif__python/appinfo/info.xml

.PHONY: run27
run27:
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:unregister to_gif --silent || true
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:register to_gif docker_dev -e --force-scopes \
		--info-xml https://raw.githubusercontent.com/cloud-py-api/app_ecosystem_v2/main/examples/to_gif__python/appinfo/info.xml

.PHONY: manual_register27
manual_register27:
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:unregister to_gif --silent || true
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:register to_gif manual_install --json-info \
  "{\"appid\":\"to_gif\",\"name\":\"to_gif\",\"daemon_config_name\":\"manual_install\",\"version\":\"1.0.0\",\"secret\":\"12345\",\"host\":\"host.docker.internal\",\"port\":9031,\"scopes\":{\"required\":[\"FILES\", \"NOTIFICATIONS\"],\"optional\":[]},\"protocol\":\"http\",\"system_app\":0}" \
  -e --force-scopes
