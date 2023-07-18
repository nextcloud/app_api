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
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExAppConfig;
use OCA\AppEcosystemV2\Db\ExAppConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * App configuration (appconfig_ex)
 */
class ExAppConfigService {
	const CACHE_TTL = 60 * 60; // 1 hour
	private LoggerInterface $logger;
	private ICache $cache;
	private ExAppConfigMapper $mapper;

	public function __construct(
		ICacheFactory $cacheFactory,
		ExAppConfigMapper $mapper,
		LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/appconfig_ex');
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	/**
	 * Get app_config_ex values
	 *
	 * @param string $appId
	 * @param array $configKeys
	 *
	 * @return array|null
	 */
	public function getAppConfigValues(string $appId, array $configKeys): ?array {
		$cacheKey = sprintf('/%s:%s', $appId, json_encode($configKeys));
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		try {
			$exAppConfigs = array_map(function (ExAppConfig $exAppConfig) {
				return [
					'configkey' => $exAppConfig->getConfigkey(),
					'configvalue' => $exAppConfig->getConfigvalue() ?? '',
				];
			}, $this->mapper->findByAppConfigKeys($appId, $configKeys));
			$this->cache->set($cacheKey, $exAppConfigs, self::CACHE_TTL);
			return $exAppConfigs;
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Set app_config_ex value
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param mixed $configValue
	 * @param int $sensitive
	 *
	 * @return ExAppConfig|null
	 */
	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue, int $sensitive = 0): ?ExAppConfig {
		$appConfigEx = $this->getAppConfig($appId, $configKey);
		if ($appConfigEx === null) {
			try {
				$appConfigEx = $this->mapper->insert(new ExAppConfig([
					'appid' => $appId,
					'configkey' => $configKey,
					'configvalue' => $configValue ?? '',
					'sensitive' => $sensitive,
				]));
				$cacheKey = sprintf('/%s:%s', $appId, $configKey);
				$this->cache->set($cacheKey, $appConfigEx, self::CACHE_TTL);
			} catch (\Exception $e) {
				$this->logger->error('Error while inserting app_config_ex value: ' . $e->getMessage(), ['exception' => $e]);
				return null;
			}
		} else {
			$appConfigEx->setConfigvalue($configValue);
			$appConfigEx->setSensitive($sensitive);
			if ($this->updateAppConfigValue($appConfigEx) !== 1) {
				$this->logger->error('Error while updating app_config_ex value');
				return null;
			}
		}
		return $appConfigEx;
	}

	/**
	 * Delete appconfig_ex values
	 *
	 * @param array $configKeys
	 * @param string $appId
	 *
	 * @return int
	 */
	public function deleteAppConfigValues(array $configKeys, string $appId): int {
		try {
			return $this->mapper->deleteByAppidConfigkeys($appId, $configKeys);
		} catch (Exception) {
			return -1;
		}
	}

	/**
	 * @param string $appId
	 *
	 * @return ExAppConfig[]
	 */
	public function getAllAppConfig(string $appId): array {
		try {
			return $this->mapper->findAllByAppid($appId);
		} catch (Exception) {
			return [];
		}
	}

	/**
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @return ExAppConfig|null
	 */
	public function getAppConfig(mixed $appId, mixed $configKey): ?ExAppConfig {
		try {
			$cacheKey = sprintf('/%s:%s', $appId, $configKey);
			$cashed= $this->cache->get($cacheKey);
			if ($cashed !== null) {
				return $cashed instanceof ExAppConfig ? $cashed : new ExAppConfig($cashed);
			}

			$exAppConfig = $this->mapper->findByAppConfigKey($appId, $configKey);
			$this->cache->set($cacheKey, $exAppConfig, self::CACHE_TTL);
			return $exAppConfig;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * @param ExAppConfig $exAppConfig
	 *
	 * @return int|null
	 */
	public function updateAppConfigValue(ExAppConfig $exAppConfig): ?int {
		try {
			$result = $this->mapper->updateAppConfigValue($exAppConfig);
			if ($result === 1) {
				$cacheKey = sprintf('/%s:%s', $exAppConfig->getAppid(), $exAppConfig->getConfigkey());
				$this->cache->set($cacheKey, $exAppConfig, self::CACHE_TTL);
			}
			return $result;
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * @param ExAppConfig $exAppConfig
	 *
	 * @return int|null
	 */
	public function deleteAppConfig(ExAppConfig $exAppConfig): ?int {
		try {
			return $this->mapper->deleteByAppidConfigkeys($exAppConfig->getAppid(), [$exAppConfig->getConfigkey()]);
		} catch (Exception) {
			return null;
		}
	}
}
