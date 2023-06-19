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

use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExAppConfig;
use OCA\AppEcosystemV2\Db\ExAppConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Cache\CappedMemoryCache;

/**
 * App configuration (appconfig_ex)
 */
class ExAppConfigService {
	private LoggerInterface $logger;
	private CappedMemoryCache $cache;
	private ExAppConfigMapper $mapper;

	public function __construct(
		CappedMemoryCache $cache,
		ExAppConfigMapper $mapper,
		LoggerInterface $logger,
	) {
		$this->cache = $cache;
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	/**
	 * Get app_config_ex value
	 *
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @return mixed
	 */
	public function getAppConfigValue(string $appId, string $configKey): mixed {
		$cacheKey = $appId . ':' . $configKey;
		$value = $this->cache->get($cacheKey);
		if ($value !== null) {
			return $value;
		}

		try {
			$appConfigEx = $this->mapper->findByAppConfigKey($appId, $configKey);
			$value = $appConfigEx->getConfigvalue();
			$this->cache->set($cacheKey, $value, Application::CACHE_TTL);
			return $value;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Set app_config_ex value
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param mixed $configValue
	 * @return ExAppConfig|null
	 */
	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue): ?ExAppConfig {
		try {
			$appConfigEx = $this->mapper->findByAppConfigKey($appId, $configKey);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$appConfigEx = null;
		}
		if ($appConfigEx === null) {
			try {
				$appConfigEx = $this->mapper->insert(new ExAppConfig([
					'appid' => $appId,
					'configkey' => $configKey,
					'configvalue' => $configValue,
				]));
			} catch (\Exception $e) {
				$this->logger->error('Error while inserting app_config_ex value: ' . $e->getMessage());
				return null;
			}
		} else {
			$appConfigEx->setConfigvalue($configValue);
			try {
				if ($this->mapper->updateAppConfigValue($appConfigEx) !== 1) {
					$this->logger->error('Error while updating app_config_ex value');
					return null;
				}
			} catch (Exception) {
				return null;
			}
		}
		return $appConfigEx;
	}

	/**
	 * Delete all app_config_ex values
	 *
	 * @param array $configKeys
	 * @param string $appId
	 *
	 * @return bool
	 */
	public function deleteAppConfigValues(array $configKeys, string $appId): bool {
		try {
			return $this->mapper->deleteByAppidConfigkeys($appId, $configKeys) === count($configKeys);
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * @param string $appId
	 *
	 * @return array
	 */
	public function getAppConfigKeys(string $appId): array {
		try {
			return array_map(function (ExAppConfig $appConfigEx) {
				return $appConfigEx->getConfigkey();
			}, $this->mapper->findAllByAppId($appId));
		} catch (Exception) {
		}
		return [];
	}
}
