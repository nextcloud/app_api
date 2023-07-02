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
				'accepts_deploy_id' => $params['accepts_deploy_id'],
				'display_name' => $params['display_name'],
				'protocol' => $params['protocol'],
				'host' => $params['host'],
				'port' => $params['port'],
				'deploy_config' => $params['deploy_config'],
			]));
			$this->cache->remove('daemon_configs');
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to register daemon config. Error: ' . $e->getMessage());
			return null;
		}
	}

	public function unregisterDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			$daemonConfig = $this->mapper->delete($daemonConfig);
			$this->cache->remove('daemon_configs');
			$this->cache->remove('daemon_config_' . $daemonConfig->getId());
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to unregister daemon config. Error: ' . $e->getMessage());
			return null;
		}
	}

	public function getDaemonConfig(int $daemonConfigId): ?DaemonConfig {
		try {
			$cacheKey = 'daemon_config_' . $daemonConfigId;
//			$cached = $this->cache->get($cacheKey);
//			if ($cached !== null) {
//				return $cached instanceof DaemonConfig ? $cached : new DaemonConfig($cached);
//			}

			$daemonConfig = $this->mapper->findById($daemonConfigId);
			$this->cache->set($cacheKey, $daemonConfig, Application::CACHE_TTL);
			return $daemonConfig;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error('Failed to get daemon config. Error: ' . $e->getMessage());
			return null;
		}
	}

	public function getRegisteredDaemonConfigs(): ?array {
		try {
			$cacheKey = 'daemon_configs';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function($cachedEntry) {
					return $cachedEntry instanceof DaemonConfig ? $cachedEntry : new DaemonConfig($cachedEntry);
				}, $cached);
			}

			$daemonConfigs = $this->mapper->findAll();
			$this->cache->set($cacheKey, $daemonConfigs);
			return $daemonConfigs;
		} catch (Exception $e) {
			$this->logger->error('Failed to get registered daemon configs. Error: ' . $e->getMessage());
			return null;
		}
	}
}
