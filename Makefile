.DEFAULT_GOAL := help

.PHONY: docs
.PHONY: html
docs html:
	rm -rf docs/_build
	$(MAKE) -C docs html

.PHONY: help
help:
	@echo "Welcome to AppAPI development. Please use \`make <target>\` where <target> is one of"
	@echo "  docs               make HTML docs"
	@echo "  html               make HTML docs"
	@echo " "
	@echo " "
	@echo "  Next commands are only for dev environment with nextcloud-docker-dev!"
	@echo "  Daemon register(Linux, socket):"
	@echo "  dock-sock          create docker daemon for Nextcloud 29, 28, 27 (/var/run/docker.sock)"
	@echo "  dock-sock27        create docker daemon for Nextcloud 27 (/var/run/docker.sock)"
	@echo "  dock-sock27-gpu    create docker daemon with GPU for Nextcloud 27 (/var/run/docker.sock)"
	@echo "  dock-sock28        create docker daemon for Nextcloud 28 (/var/run/docker.sock)"
	@echo "  dock-sock28-gpu    create docker daemon with GPU for Nextcloud 28 (/var/run/docker.sock)"
	@echo "  dock-sock          create docker daemon for Nextcloud Last (/var/run/docker.sock)"
	@echo "  dock-sock-gpu      create docker daemon with GPU for Nextcloud Last (/var/run/docker.sock)"
	@echo " "
	@echo "  Daemon register(any OS, host:port)"
	@echo "  dock2port          will map docker socket to port. first use this!"
	@echo "  dock-port27        create docker daemon for Nextcloud 27 (host.docker.internal:8443)"
	@echo "  dock-port28        create docker daemon for Nextcloud 28 (host.docker.internal:8443)"
	@echo "  dock-port          create docker daemons for Nextcloud Last (host.docker.internal:8443)"

.PHONY: dock-sock27
dock-sock27:
	@echo "creating daemon for nextcloud 'stable27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev Docker docker-install http /var/run/docker.sock http://stable27.local/index.php --net=master_default

.PHONY: dock-sock27-gpu
dock-sock27-gpu:
	@echo "creating daemon with NVIDIA gpu for nextcloud 'stable27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev_nvidia || true
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev_gpu "Docker with GPU" docker-install http /var/run/docker.sock http://stable27.local/index.php --net=master_default --gpu --set-default

.PHONY: dock-sock28
dock-sock28:
	@echo "creating daemon for nextcloud 'stable28' container"
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev Docker docker-install http /var/run/docker.sock http://stable28.local/index.php --net=master_default

.PHONY: dock-sock28-gpu
dock-sock28-gpu:
	@echo "creating daemon with NVIDIA gpu for nextcloud 'stable28' container"
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev_nvidia || true
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev_gpu "Docker with GPU" docker-install http /var/run/docker.sock http://stable28.local/index.php --net=master_default --gpu --set-default

.PHONY: dock-sock
dock-sock:
	@echo "creating daemon for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev Docker docker-install http /var/run/docker.sock http://nextcloud.local/index.php --net=master_default

.PHONY: dock-sock-gpu
dock-sock-gpu:
	@echo "creating daemon with NVIDIA gpu for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev_nvidia || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev_gpu "Docker with GPU" docker-install http /var/run/docker.sock http://nextcloud.local/index.php --net=master_default --gpu --set-default

.PHONY: dock2port
dock2port:
	@echo "deploying Docker-Socket-Proxy.."
	docker run -e NC_HAPROXY_PASSWORD="some_secure_password" \
      -v /var/run/docker.sock:/var/run/docker.sock \
      --name aa-docker-socket-proxy -h aa-docker-socket-proxy \
      --net=master_default \
      --restart unless-stopped --privileged -d ghcr.io/cloud-py-api/aa-docker-socket-proxy:latest

.PHONY: dock-port27
dock-port27:
	@echo "creating daemon for nextcloud '27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:register \
        docker_dev Docker docker-install http aa-docker-socket-proxy:2375 http://stable27.local/index.php \
        --net=master_default --haproxy_password="some_secure_password"

.PHONY: dock-port28
dock-port28:
	@echo "creating daemon for nextcloud '27' container"
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:register \
        docker_dev Docker docker-install http aa-docker-socket-proxy:2375 http://stable28.local/index.php \
        --net=master_default --haproxy_password="some_secure_password"

.PHONY: dock-port
dock-port:
	@echo "creating daemon for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:register \
    	docker_dev Docker docker-install http aa-docker-socket-proxy:2375 http://nextcloud.local/index.php \
    	--net=master_default --haproxy_password="some_secure_password"
