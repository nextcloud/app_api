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

use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

use OCP\Cache\CappedMemoryCache;

use OCA\AppEcosystemV2\Db\DaemonConfigMapper;

/**
 * Daemon configuration (daemons)
 */
class DaemonConfigService {
	private LoggerInterface $logger;
	private CappedMemoryCache $cache;
	private DaemonConfigMapper $mapper;

	public function __construct(
		CappedMemoryCache $cache,
		DaemonConfigMapper $mapper,
		LoggerInterface $logger,
	) {
		$this->cache = $cache;
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	public function registerDaemonConfig(array $params): ?DaemonConfig {
		try {
			return $this->mapper->insert(new DaemonConfig([
				'accepts_deploy_id' => $params['accepts_deploy_id'],
				'display_name' => $params['display_name'],
				'protocol' => $params['protocol'],
				'host' => $params['host'],
				'port' => $params['port'],
				'deploy_config' => $params['deploy_config'],
			]));
		} catch (Exception $e) {
			$this->logger->error('Failed to register daemon config. Error: ' . $e->getMessage());
			return null;
		}
	}

	public function unregisterDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			return $this->mapper->delete($daemonConfig);
		} catch (Exception $e) {
			$this->logger->error('Failed to unregister daemon config. Error: ' . $e->getMessage());
			return null;
		}
	}

	public function getDaemonConfig(int $daemonConfigId): ?DaemonConfig {
		try {
			return $this->mapper->findById($daemonConfigId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error('Failed to get daemon config. Error: ' . $e->getMessage());
			return null;
		}
	}

	public function getRegisteredDaemonConfigs(): ?array {
		try {
			return $this->mapper->findAll();
		} catch (Exception $e) {
			$this->logger->error('Failed to get registered daemon configs. Error: ' . $e->getMessage());
			return null;
		}
	}
}
