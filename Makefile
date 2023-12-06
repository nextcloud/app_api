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
	@echo "  dock-certs         deploy certs, second use this!"
	@echo "  dock-port27        create docker daemon for Nextcloud 27 (host.docker.internal:8443)"
	@echo "  dock-port28        create docker daemon for Nextcloud 28 (host.docker.internal:8443)"
	@echo "  dock-port          create docker daemons for Nextcloud Last (host.docker.internal:8443)"

.PHONY: dock-sock27
dock-sock27:
	@echo "creating daemon for nextcloud 'stable27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev Docker docker-install unix-socket /var/run/docker.sock http://stable27.local/index.php --net=master_default

.PHONY: dock-sock27-gpu
dock-sock27-gpu:
	@echo "creating daemon with NVIDIA gpu for nextcloud 'stable27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev_nvidia || true
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev_gpu "Docker with GPU" docker-install unix-socket /var/run/docker.sock http://stable27.local/index.php --net=master_default --gpu --set-default

.PHONY: dock-sock28
dock-sock28:
	@echo "creating daemon for nextcloud 'stable28' container"
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev Docker docker-install unix-socket /var/run/docker.sock http://stable28.local/index.php --net=master_default

.PHONY: dock-sock28-gpu
dock-sock28-gpu:
	@echo "creating daemon with NVIDIA gpu for nextcloud 'stable28' container"
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev_nvidia || true
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev_gpu "Docker with GPU" docker-install unix-socket /var/run/docker.sock http://stable28.local/index.php --net=master_default --gpu --set-default

.PHONY: dock-sock
dock-sock:
	@echo "creating daemon for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev Docker docker-install unix-socket /var/run/docker.sock http://nextcloud.local/index.php --net=master_default

.PHONY: dock-sock-gpu
dock-sock-gpu:
	@echo "creating daemon with NVIDIA gpu for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev_nvidia || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:register \
		docker_dev_gpu "Docker with GPU" docker-install unix-socket /var/run/docker.sock http://nextcloud.local/index.php --net=master_default --gpu --set-default

.PHONY: dock2port
dock2port:
	@echo "deploying kekru/docker-remote-api-tls..."
	docker run --name dock_api2port -d -p 6443:443 -v /var/run/docker.sock:/var/run/docker.sock:ro \
		--env CREATE_CERTS_WITH_PW=supersecret --env CERT_HOSTNAME=host.docker.internal \
		-v `pwd`/certs:/data/certs kekru/docker-remote-api-tls:master
	@echo "waiting 20 seconds to finish generating certificates..."
	sleep 20

.PHONE: dock-certs
dock-certs:
	@echo "copying certs to Nextcloud 27"
	docker cp ./certs/client/ master-stable27-1:/ || echo "Failed copying certs to Nextcloud 27"
	docker exec master-stable27-1 sudo -u www-data php occ security:certificates:import /client/ca.pem || true
	@echo "copying certs to Nextcloud 28"
	docker cp ./certs/client/ master-stable28-1:/ || echo "Failed copying certs to Nextcloud 28"
	docker exec master-stable28-1 sudo -u www-data php occ security:certificates:import /client/ca.pem || true
	@echo "copying certs to Nextcloud Last"
	docker cp ./certs/client/ master-nextcloud-1:/ || echo "Failed copying certs to Nextcloud 'Last'"
	docker exec master-nextcloud-1 sudo -u www-data php occ security:certificates:import /client/ca.pem || true

.PHONY: dock-port27
dock-port27:
	@echo "creating daemon for nextcloud '27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable27-1 sudo -u www-data php occ app_api:daemon:register \
        docker_dev Docker docker-install https host.docker.internal:6443 http://stable27.local/index.php \
        --net=master_default --ssl_cert /client/cert.pem --ssl_key /client/key.pem

.PHONY: dock-port28
dock-port28:
	@echo "creating daemon for nextcloud '27' container"
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-stable28-1 sudo -u www-data php occ app_api:daemon:register \
        docker_dev Docker docker-install https host.docker.internal:6443 http://stable28.local/index.php \
        --net=master_default --ssl_cert /client/cert.pem --ssl_key /client/key.pem

.PHONY: dock-port
dock-port:
	@echo "creating daemon for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:unregister docker_dev || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_api:daemon:register \
    	docker_dev Docker docker-install https host.docker.internal:6443 http://nextcloud.local/index.php \
    	--net=master_default --ssl_cert /client/cert.pem --ssl_key /client/key.pem
