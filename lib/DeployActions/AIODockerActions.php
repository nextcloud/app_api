<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\DeployActions;

use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCA\AppEcosystemV2\Service\DaemonConfigService;
use OCP\IConfig;

/**
 * Class with utils methods for AIO setup
 */
class AIODockerActions {
	public const AIO_DAEMON_CONFIG_NAME = 'docker_aio';
	public const AIO_DOCKER_SOCKET_PROXY_HOST = 'nextcloud-aio-docker-socket-proxy:2375';
	public const AIO_DAEMON_HOST = 'http://nextcloud-aio-apache:11000/index.php';
	private IConfig $config;
	private DaemonConfigService $daemonConfigService;

	public function __construct(
		IConfig $config,
		DaemonConfigService $daemonConfigService
	) {
		$this->config = $config;
		$this->daemonConfigService = $daemonConfigService;
	}

	/**
	 * Detecting AIO instance by config setting or AIO_TOKEN env as fallback
	 *
	 * @return bool
	 */
	public function isAIO(): bool {
		return $this->config->getSystemValue('one-click-instance', false);
	}

	/**
	 * Registers DaemonConfig with default params to use AIO Docker Socket Proxy
	 *
	 * @return DaemonConfig|null
	 */
	public function registerAIODaemonConfig(): ?DaemonConfig {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName(self::AIO_DAEMON_CONFIG_NAME);
		if ($daemonConfig !== null) {
			return null;
		}

		$deployConfig = [
			'net' => 'nextcloud-aio', // using the same host as default network for Nextcloud AIO containers
			'host' => null,
			'nextcloud_url' => self::AIO_DAEMON_HOST, // AIO default (11000) apache proxy to Nextcloud
			'ssl_key' => null,
			'ssl_key_password' => null,
			'ssl_cert' => null,
			'ssl_cert_password' => null,
			'gpus' => [],
		];

		if ($this->isGPUsEnabled()) {
			$deployConfig['gpus'] = ['/dev/dri'];
		}

		$daemonConfigParams = [
			'name' => self::AIO_DAEMON_CONFIG_NAME,
			'display_name' => 'AIO Docker Socket Proxy',
			'accepts_deploy_id' => 'docker-install',
			'protocol' => 'http',
			'host' => self::AIO_DOCKER_SOCKET_PROXY_HOST,
			'deploy_config' => $deployConfig,
		];

		return $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
	}

	/**
	 * Check if /dev/dri folder mounted to the container.
	 * In AIO this means that NEXTCLOUD_ENABLE_DRI_DEVICE=true
	 *
	 * @return bool
	 */
	private function isGPUsEnabled(): bool {
		$devDri = '/dev/dri';
		if (is_dir($devDri)) {
			return true;
		}
		return false;
	}
}
