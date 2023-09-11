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
	@echo "  dock-sock          create docker daemon for Nextcloud 28, 27, 26 (/var/run/docker.sock)"
	@echo "  dock-sock28        create docker daemon for Nextcloud 28 (/var/run/docker.sock)"
	@echo "  dock-sock27        create docker daemon for Nextcloud 27 (/var/run/docker.sock)"
	@echo "  dock-sock26        create docker daemon for Nextcloud 26 (/var/run/docker.sock)"
	@echo " "
	@echo "  Daemon register(any OS, host:port)"
	@echo "  dock2port          will map docker socket to port. first use this!"
	@echo "  dock-certs         deploy certs, second use this!"
	@echo "  dock-port          create docker daemons for Nextcloud 28, 27, 26 (host.docker.internal:8443)"
	@echo "  dock-port28        create docker daemon for Nextcloud 28 (host.docker.internal:8443)"
	@echo "  dock-port27        create docker daemon for Nextcloud 27 (host.docker.internal:8443)"
	@echo "  dock-port26        create docker daemon for Nextcloud 26 (host.docker.internal:8443)"
	@echo " "
	@echo " "
	@echo "  example-deploy     deploy Example App to docker"
	@echo "  example28          register & enable Example App in Nextcloud 28"
	@echo "  example27          register & enable Example App in Nextcloud 27"
	@echo "  example26          register & enable Example App in Nextcloud 26"

.PHONY: dock-sock
dock-sock:
	$(MAKE) dock-sock28 dock-sock27 dock-sock26

.PHONY: dock-sock28
dock-sock28:
	@echo "creating daemon for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:daemon:unregister docker_dev || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:daemon:register \
		docker_dev Docker docker-install unix-socket /var/run/docker.sock http://nextcloud/index.php --net=master_default

.PHONY: dock-sock27
dock-sock27:
	@echo "creating daemon for nextcloud 'stable27' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:daemon:unregister docker_dev || true
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:daemon:register \
		docker_dev Docker docker-install unix-socket /var/run/docker.sock http://stable27/index.php --net=master_default

.PHONY: dock-sock26
dock-sock26:
	@echo "creating daemon for nextcloud 'stable26' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:daemon:unregister docker_dev || true
	docker exec master-stable26-1 sudo -u www-data php occ app_ecosystem_v2:daemon:register \
		docker_dev Docker docker-install unix-socket /var/run/docker.sock http://stable26/index.php --net=master_default

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
	@echo "copying certs to Nextcloud Master"
	docker cp ./certs/client/ master-nextcloud-1:/ || echo "Failed copying certs to Nextcloud 'master'"
	docker exec master-nextcloud-1 sudo -u www-data php occ security:certificates:import /client/ca.pem || true
	@echo "copying certs to Nextcloud 27"
	docker cp ./certs/client/ master-stable27-1:/ || echo "Failed copying certs to Nextcloud 27"
	docker exec master-stable27-1 sudo -u www-data php occ security:certificates:import /client/ca.pem || true
	@echo "copying certs to Nextcloud 26"
	docker cp ./certs/client/ master-stable26-1:/ || echo "Failed copying certs to Nextcloud 26"
	docker exec master-stable26-1 sudo -u www-data php occ security:certificates:import /client/ca.pem || true

.PHONY: dock-port
dock-port:
	$(MAKE) dock-port28 dock-port27 dock-port26

.PHONY: dock-port28
dock-port28:
	@echo "creating daemon for nextcloud 'master' container"
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:daemon:unregister docker_dev || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:daemon:register \
    	docker_dev Docker docker-install https host.docker.internal:6443 http://nextcloud/index.php \
    	--net=master_default --ssl_cert /client/cert.pem --ssl_key /client/key.pem

.PHONY: dock-port27
dock-port27:
	@echo "creating daemon for nextcloud '27' container"
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:daemon:unregister docker_dev || true
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:daemon:register \
        docker_dev Docker docker-install https host.docker.internal:6443 http://stable27/index.php \
        --net=master_default --ssl_cert /client/cert.pem --ssl_key /client/key.pem

.PHONY: dock-port26
dock-port26:
	@echo "creating daemon for nextcloud '26' container"
	docker exec master-stable26-1 sudo -u www-data php occ app_ecosystem_v2:daemon:unregister docker_dev || true
	docker exec master-stable26-1 sudo -u www-data php occ app_ecosystem_v2:daemon:register \
        docker_dev Docker docker-install https host.docker.internal:6443 http://stable26/index.php \
        --net=master_default --ssl_cert /client/cert.pem --ssl_key /client/key.pem

.PHONY: example-deploy
example-deploy:
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:app:deploy skeleton docker_dev \
    		--info-xml https://raw.githubusercontent.com/cloud-py-api/nc_py_api/main/examples/as_app/skeleton/appinfo/info.xml

.PHONY: example28
example28:
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:app:unregister skeleton --silent || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:app:register skeleton docker_dev
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:app:enable skeleton

.PHONY: example27
example27:
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:unregister skeleton --silent || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:app:register skeleton docker_dev
	docker exec master-stable27-1 sudo -u www-data php occ app_ecosystem_v2:app:enable skeleton

.PHONY: example26
example26:
	docker exec master-stable26-1 sudo -u www-data php occ app_ecosystem_v2:app:unregister skeleton --silent || true
	docker exec master-nextcloud-1 sudo -u www-data php occ app_ecosystem_v2:app:register skeleton docker_dev
	docker exec master-stable26-1 sudo -u www-data php occ app_ecosystem_v2:app:enable skeleton
