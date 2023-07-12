# AppEcosystemV2 - Deployment process

> Draft


## Overview

App Ecosystem V2 deployment process consists of 3 steps:

1. Register daemon config (see [daemon config](#daemon-config-registration))
2. Deploy ExApp on registered daemon (see [ExApp deployment](#exapp-deployment))
3. Register ExApp (see [ExApp registration](#exapp-registration))


## Daemon config registration

The first step is to register daemon config. This is done using `occ` CLI tool or in AppEcosystemV2 UI (`to be implemented`).

### CLI

Daemon config registration is done using `occ` CLI tool:

```
php occ app_ecosystem_v2:daemon:register [--net NET] [--expose [EXPOSE]] [--host HOST] [--] <accepts-deploy-id> <display-name> <protocol> <host> <port>
```

arguments:

- `accepts-deploy-id` - `[required]` type of deployment (for now only `docker-install` is supported)
- `display-name` - `[required]` name of the daemon (e.g. `My Local Docker`, will be displayed in the UI)
- `protocol` - `[required]` protocol used to connect to the daemon (`unix-socket`, `network`)
- `host` - `[required]` host of the daemon (e.g. `/var/run/docker.sock` for `unix-socket` protocol or `http(s)://host:port` for `network` protocol)
- `port` - `[optional]` port of the daemon (required only for `network` protocol)

options:

- `--net [network-name]`  - `[required]` network name to bind docker container to (default: `host`)
- `--expose [EXPOSE]` - `[required]` expose daemon to the internet (default: `false`)
- `--host HOST` - `[required]` host to expose daemon to (defaults to ExApp appid)

### Deploy config

Deploy config is a set of options in Daemon config. It is used in deployment process to configure ExApp container params:

```json
{
	"net": "bridge",
	"expose": null,
	"host": "host.docker.internal"
}
```

- `net` - `[required]` network name to bind docker container to (default: `host`)
- `expose` - `[required]` expose container to local, global or none (default: `null`)
- `host` - `[required]` host to expose container to (defaults to ExApp appid)

### Example

Let's say we want to register local docker daemon, which can be accessed by Nextcloud instance (or container) 
using unix socket `/var/run/docker.sock` (it must be forwarded and have enough access rules for webserver user).

```
php occ app_ecosystem_v2:daemon:register docker-install "My Local Docker" unix-socket /var/run/docker.sock 0 --net bridge --expose local --host host.docker.internal
```


## ExApp deployment

The second step is to deploy ExApp on registered daemon. This is done using `occ` CLI tool or in AppEcosystemV2 UI (`to be implemented`). 

### CLI

```
app_ecosystem_v2:app:deploy [--info-xml INFO-XML] [--ssl_key SSL_KEY] [--ssl_password SSL_PASSWORD] [--ssl_cert SSL_CERT] [--ssl_cert_password SSL_CERT_PASSWORD] [-e|--env ENV] [--] <appid> <daemon-config-id>
```

arguments:

- `appid` - `[required]` appid of the ExApp
- `daemon-config-id` - `[required]` daemon config id to use for ExApp deployment

options:

- `info-xml` - `[required]` path to info.xml (see [info.xml schema](#exapp-infoxml-schema)) file (url or local absolute path)
- `ssl_key` - `[optional]` path to SSL key file (local absolute path), may be required in some cases (e.g. remote docker daemon with TLS enabled, see [docker daemon TLS](#docker-daemon-port)
- `ssl_key_password` - `[optional]` SSL key password
- `ssl_cert` - `[optional]` path to SSL cert file (local absolute path)
- `ssl_cert_password` - `[optional]` SSL cert password
- `env` - `[required]` environment variables to pass to the docker container (list of required env variables is defined below [deploy env variables](#deploy-env-variables))

Successful deployment will return the following JSON output which is used then in ExApp registration:

```json
{
	"appid": "app_python_skeleton",
	"name":"App Python Skeleton",
	"daemon_config_id": 1,
	"version":"1.0.0",
	"secret":"***generated-secret***",
	"host":"app_python_skeleton",
	"port":"9001",
	"system_app": true
}
```

### Deploy env variables

Deploy env variables are used to configure ExApp. 
The following env variables are required (additional envs can be passed using multiple `--env ENV_NAME=ENV_VAL` options):

- `AE_VERSION` - `[automaticly]` AppEcosystemV2 version
- `APP_SECRET` - `[automaticly]` generated shared secret used for AppEcosystemV2 Auth
- `APP_ID` - `[automaticly]` ExApp appid
- `APP_VERSION` - `[automaticly]` ExApp version
- `APP_HOST` - `[automaticly]` host ExApp is listening on
- `APP_PORT` - `[automaticly]` port ExApp is listening on
- `NEXTCLOUD_URL` - `[automaticly]` Nextcloud URL to connect to

### Example

Let's say we want to deploy ExApp with appid `app_python_skeleton` and version `1.0.0` on local docker daemon registered in previous step.

```
php occ app_ecosystem_v2:app:deploy app_python_skeleton 1 --info-xml https://raw.githubusercontent.com/cloud-py-api/py_app_v2-skeleton/main/appinfo/info.xml
```

# Docker daemon port

If you want to connect to remote docker daemon with TLS enabled, you need to provide SSL key and cert by provided options.
Important: before deploy you need to import ca.pem file using occ command:

```
php occ security:certificates:import /path/to/ca.pem
```

The daemon must be configured with `protocol=net`, `host=https://dockerapihost`, `port=8443`.
More info about how to configure daemon will be added soon.

## ExApp registration

The third step is to register ExApp. This is done using `occ` CLI tool or in AppEcosystemV2 UI (`to be implemented`).
The register command requires JSON output from ExApp deployment step, so it can be combined into one command (forward output from deploy to register command).

### CLI

```
app_ecosystem_v2:app:register [-e|--enabled] [--force-scopes] [--] <deploy-json-output>
```

arguments:

- `deploy-json-output` - `[required]` JSON output from ExApp deployment step

options:
- `enabled` - `[required]` enable ExApp after registration
- `force-scopes` - `[required]` force scopes for ExApp (no confirmation prompts)

### Example

Let's say we want to register ExApp with appid `app_python_skeleton` and version `1.0.0` on local docker daemon registered in previous step.

```
php occ app_ecosystem_v2:app:register {"appid":"app_python_skeleton","name":"App Python Skeleton","daemon_config_id":1,"version":"1.0.0","secret":"***secret***","host":"app_python_skeleton","port":"9001", "protocol": "http", "system_app": false} --enabled --force-scopes
```

combined with deploy step:

```
php occ app_ecosystem_v2:app:register --enabled --force-scopes "$(php occ app_ecosystem_v2:app:deploy app_python_skeleton 1 --info-xml https://raw.githubusercontent.com/cloud-py-api/py_app_v2-skeleton/main/appinfo/info.xml)"
```

## ExApp info.xml Schema

ExApp info.xml ([example repo](https://github.com/cloud-py-api/py_app_v2-skeleton)) file is used to describe ExApp.
It is used to generate ExApp docker container and to register ExApp in Nextcloud.
It has the same structure as Nextcloud appinfo/info.xml file, but with some additional fields:

```xml
<ex-app>
	<docker-install>
		<registry>ghcr.io</registry>
		<image>cloud-py-api/py_app_v2-skeleton</image>
		<image-tag>latest</image-tag>
	</docker-install>
	<protocol>http</protocol>
	<system>0</system>
</ex-app>
```
