<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use Psr\Log\LoggerInterface;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCA\AppEcosystemV2\Db\DaemonConfigMapper;

/**
 * Daemon configuration (daemons)
 */
class DaemonConfigService {
	const CACHE_TTL = 60 * 60 * 2; // 2 hours
	private LoggerInterface $logger;
	private ICache $cache;
	private DaemonConfigMapper $mapper;

	public function __construct(
		LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		DaemonConfigMapper $mapper,
	) {
		$this->logger = $logger;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/daemon_configs');
		$this->mapper = $mapper;
	}

	public function registerDaemonConfig(array $params): ?DaemonConfig {
		try {
			$daemonConfig = $this->mapper->insert(new DaemonConfig([
				'name' => $params['name'],
				'display_name' => $params['display_name'],
				'accepts_deploy_id' => $params['accepts_deploy_id'],
				'protocol' => $params['protocol'],
				'host' => $params['host'],
				'deploy_config' => $params['deploy_config'],
			]));
			$this->cache->remove('/daemon_configs');
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to register daemon config. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function unregisterDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			$daemonConfig = $this->mapper->delete($daemonConfig);
			$this->cache->remove('/daemon_configs');
			$this->cache->remove('/daemon_config_' . $daemonConfig->getName());
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to unregister daemon config. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function getRegisteredDaemonConfigs(): ?array {
		try {
			$cacheKey = '/daemon_configs';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function($cachedEntry) {
					return $cachedEntry instanceof DaemonConfig ? $cachedEntry : new DaemonConfig($cachedEntry);
				}, $cached);
			}

			$daemonConfigs = $this->mapper->findAll();
			$this->cache->set($cacheKey, $daemonConfigs, self::CACHE_TTL);
			return $daemonConfigs;
		} catch (Exception $e) {
			$this->logger->error('Failed to get registered daemon configs. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function getDaemonConfigByName(string $name): ?DaemonConfig {
		try {
			$cacheKey = '/daemon_config_' . $name;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached instanceof DaemonConfig ? $cached : new DaemonConfig($cached);
			}

			$daemonConfig = $this->mapper->findByName($name);
			$this->cache->set($cacheKey, $daemonConfig, self::CACHE_TTL);
			return $daemonConfig;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error('Failed to get daemon config by name. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}
}
