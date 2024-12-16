<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExAppConfig;
use OCA\AppAPI\Db\ExAppConfigMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * App configuration (appconfig_ex)
 */
class ExAppConfigService {

	public function __construct(
		private ExAppConfigMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

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

	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue, ?int $sensitive = null): ?ExAppConfig {
		$appConfigEx = $this->getAppConfig($appId, $configKey);
		if ($appConfigEx === null) {
			try {
				$appConfigEx = $this->mapper->insert(new ExAppConfig([
					'appid' => $appId,
					'configkey' => $configKey,
					'configvalue' => $configValue ?? '',
					'sensitive' => $sensitive ?? 0,
				]));
			} catch (Exception $e) {
				$this->logger->error(sprintf('Failed to insert appconfig_ex value. Error: %s', $e->getMessage()), ['exception' => $e]);
				return null;
			}
		} else {
			$appConfigEx->setConfigvalue($configValue);
			if ($sensitive !== null) {
				$appConfigEx->setSensitive($sensitive);
			}
			if ($this->updateAppConfigValue($appConfigEx) === null) {
				$this->logger->error(sprintf('Error while updating appconfig_ex %s value.', $configKey));
				return null;
			}
		}
		return $appConfigEx;
	}

	public function deleteAppConfigValues(array $configKeys, string $appId): int {
		try {
			return $this->mapper->deleteByAppidConfigkeys($appId, $configKeys);
		} catch (Exception) {
			return -1;
		}
	}

	/**
	 * @return ExAppConfig[]
	 */
	public function getAllAppConfig(string $appId): array {
		try {
			return $this->mapper->findAllByAppid($appId);
		} catch (Exception) {
			return [];
		}
	}

	public function getAppConfig(mixed $appId, mixed $configKey): ?ExAppConfig {
		try {
			return $this->mapper->findByAppConfigKey($appId, $configKey);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function updateAppConfigValue(ExAppConfig $exAppConfig): ?ExAppConfig {
		try {
			return $this->mapper->update($exAppConfig);
		} catch (Exception) {
			return null;
		}
	}

	public function deleteAppConfig(ExAppConfig $exAppConfig): ?int {
		try {
			return $this->mapper->deleteByAppidConfigkeys($exAppConfig->getAppid(), [$exAppConfig->getConfigkey()]);
		} catch (Exception) {
			return null;
		}
	}
}
