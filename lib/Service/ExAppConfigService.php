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

use OCA\AppEcosystemV2\Db\ExAppConfig;
use OCA\AppEcosystemV2\Db\ExAppConfigMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * App configuration (appconfig_ex)
 */
class ExAppConfigService {
	private LoggerInterface $logger;
	private ExAppConfigMapper $mapper;

	public function __construct(
		ExAppConfigMapper $mapper,
		LoggerInterface $logger,
	) {
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
		try {
			return array_map(function (ExAppConfig $exAppConfig) {
				return [
					'configkey' => $exAppConfig->getConfigkey(),
					'configvalue' => $exAppConfig->getConfigvalue() ?? '',
				];
			}, $this->mapper->findByAppConfigKeys($appId, $configKeys));
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Set appconfig_ex value
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
			} catch (Exception $e) {
				$this->logger->error(sprintf('Failed to insert appconfig_ex value. Error: %s', $e->getMessage()), ['exception' => $e]);
				return null;
			}
		} else {
			$appConfigEx->setConfigvalue($configValue);
			$appConfigEx->setSensitive($sensitive);
			if ($this->updateAppConfigValue($appConfigEx) !== 1) {
				$this->logger->error(sprintf('Error while updating appconfig_ex %s value.', $configKey));
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
			return $this->mapper->findByAppConfigKey($appId, $configKey);
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
			return $this->mapper->updateAppConfigValue($exAppConfig);
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
