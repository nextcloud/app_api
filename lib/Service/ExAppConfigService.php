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

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExAppConfig;
use OCA\AppEcosystemV2\Db\ExAppConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\Cache\CappedMemoryCache;

/**
 * App configuration (appconfig_ex)
 */
class ExAppConfigService {
	/** @var IConfig */
	private $config;

	/** @var LoggerInterface */
	private $logger;

	/** @var CappedMemoryCache */
	private $cache;

	/** @var IClient */
	private $client;

	/** @var ExAppConfigMapper */
	private $mapper;

	public function __construct(
		IConfig $config,
		CappedMemoryCache $cache,
		IClientService $clientService,
		ExAppConfigMapper $mapper,
		LoggerInterface $logger,
	) {
		$this->config = $config;
		$this->cache = $cache;
		$this->client = $clientService->newClient();
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	/**
	 * Get app_config_ex value
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	public function getAppConfigValue(string $appId, string $configKey, mixed $default = ''): mixed {
		$cacheKey = $appId . ':' . $configKey;
		$value = $this->cache->get($cacheKey);
		if ($value !== null) {
			return $value;
		}

		try {
			$appConfigEx = $this->mapper->findByAppConfigKey($appId, $configKey);
			$value = $appConfigEx->getConfigvalue();
		} catch (DoesNotExistException $e) {
			$value = $default; // TODO: do we need default values?
		}

		$this->cache->set($cacheKey, $value, Application::CACHE_TTL);
		return $value;
	}

	/**
	 * Set app_config_ex value
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param mixed $value
	 *
	 * @return Entity|null
	 */
	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue): ?Entity {
		try {
			/** @var ExAppConfig $appConfigEx */
			$appConfigEx = $this->mapper->findByAppConfigKey($appId, $configKey);
		} catch (DoesNotExistException $e) {
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
			if ($this->mapper->updateAppConfigValue($appConfigEx) !== 1) {
				$this->logger->error('Error while updating app_config_ex value');
				return null;
			}
		}
		return $appConfigEx;
	}

	/**
	 * Delete app_config_ex value
	 *
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @return Entity|null
	 */
	public function deleteAppConfigValue(string $appId, string $configKey): ?Entity {
		/** @var ExAppConfig $appConfigEx */
		$appConfigEx = $this->mapper->findByAppConfigKey($appId, $configKey);
		if ($appConfigEx !== null) {
			if ($this->mapper->deleteByAppidConfigkey($appConfigEx) !== 1) {
				$this->logger->error('Error while deleting app_config_ex value');
				return null;
			}
		}
		return $appConfigEx;
	}

	/**
	 * Delete all app_config_ex values
	 *
	 * @param string $appId
	 *
	 * @return int deleted items count
	 */
	public function deleteAppConfigValues(string $appId) {
		return $this->mapper->deleteAllByAppId($appId);
	}

	/**
	 * @param string $appId
	 *
	 * @return Entity[]
	 */
	public function getAppConfigKeys(string $appId) {
		$appConfigExs = $this->mapper->findAllByAppId($appId);
		$this->logger->error('getAppConfigKeys: ' . json_encode($appConfigExs));
		return $appConfigExs;
		// $keys = [];
		// foreach ($appConfigExs as $appConfigEx) {
		// 	$keys[] = $appConfigEx->getConfigkey();
		// }
		// return $keys;
	}
}
