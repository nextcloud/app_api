<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\IAppConfig;

/**
 * Class with utils methods for AIO setup
 */
class AIODockerActions {
	public const AIO_DAEMON_CONFIG_NAME = 'docker_aio';
	public const AIO_DOCKER_SOCKET_PROXY_HOST = 'nextcloud-aio-docker-socket-proxy:2375';
	public const AIO_HARP_DAEMON_CONFIG_NAME = 'harp_aio';
	public const AIO_HARP_HOST = 'nextcloud-aio-harp:8780';

	public function __construct(
		private readonly IAppConfig    		 $appConfig,
		private readonly DaemonConfigService $daemonConfigService
	) {
	}

	/**
	 * Detecting AIO instance by config setting or AIO_TOKEN env as fallback
	 */
	public function isAIO(): bool {
		return filter_var(getenv('THIS_IS_AIO'), FILTER_VALIDATE_BOOL);
	}

	/**
	 * Registers DaemonConfig with default params to use AIO Docker Socket Proxy
	 */
	public function registerAIODaemonConfig(): ?DaemonConfig {
		$defaultDaemonConfig = $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', lazy: true);
		if ($defaultDaemonConfig !== '') {
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName(self::AIO_DAEMON_CONFIG_NAME);
			if ($daemonConfig !== null) {
				return null;
			}
		}

		$deployConfig = [
			'net' => 'nextcloud-aio', // using the same host as default network for Nextcloud AIO containers
			'nextcloud_url' => 'https://' . getenv('NC_DOMAIN'),
			'haproxy_password' => null,
			'computeDevice' => [
				'id' => 'cpu',
				'label' => 'CPU',
			],
		];

		$daemonConfigParams = [
			'name' => self::AIO_DAEMON_CONFIG_NAME,
			'display_name' => 'AIO Docker Socket Proxy',
			'accepts_deploy_id' => 'docker-install',
			'protocol' => 'http',
			'host' => self::AIO_DOCKER_SOCKET_PROXY_HOST,
			'deploy_config' => $deployConfig,
		];

		$daemonConfig = $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
		if ($daemonConfig !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName(), lazy: true);
		}
		return $daemonConfig;
	}

	/**
	 * Check if HaRP is enabled in AIO
	 */
	public function isHarpEnabled(): bool {
		$harpEnabled = getenv('HARP_ENABLED');
		return $harpEnabled === 'yes' || $harpEnabled === 'true' || $harpEnabled === '1';
	}

	/**
	 * Check if Docker Socket Proxy is enabled in AIO
	 */
	public function isDockerSocketProxyEnabled(): bool {
		$dspEnabled = getenv('DOCKER_SOCKET_PROXY_ENABLED');
		return $dspEnabled === 'yes' || $dspEnabled === 'true' || $dspEnabled === '1';
	}

	/**
	 * Get the HP_SHARED_KEY from environment
	 */
	public function getHarpSharedKey(): ?string {
		$key = getenv('HP_SHARED_KEY');
		return $key !== false && $key !== '' ? $key : null;
	}

	/**
	 * Registers DaemonConfig with default params to use AIO HaRP
	 */
	public function registerAIOHarpDaemonConfig(): ?DaemonConfig {
		// Check if HaRP daemon config already exists
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName(self::AIO_HARP_DAEMON_CONFIG_NAME);
		if ($daemonConfig !== null) {
			return null;
		}

		$harpSharedKey = $this->getHarpSharedKey();
		if ($harpSharedKey === null) {
			return null;
		}

		$deployConfig = [
			'net' => 'nextcloud-aio',
			'nextcloud_url' => 'https://' . getenv('NC_DOMAIN'),
			'haproxy_password' => $harpSharedKey, // will be encrypted by DaemonConfigService
			'harp' => [
				'exapp_direct' => true,
			],
			'computeDevice' => [
				'id' => 'cpu',
				'label' => 'CPU',
			],
		];

		$daemonConfigParams = [
			'name' => self::AIO_HARP_DAEMON_CONFIG_NAME,
			'display_name' => 'AIO HaRP',
			'accepts_deploy_id' => 'docker-install',
			'protocol' => 'http',
			'host' => self::AIO_HARP_HOST,
			'deploy_config' => $deployConfig,
		];

		$daemonConfig = $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
		if ($daemonConfig !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName(), lazy: true);
		}
		return $daemonConfig;
	}
}
