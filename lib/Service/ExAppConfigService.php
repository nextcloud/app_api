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
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * App configuration (appconfig_ex)
 */
class ExAppConfigService {

	public function __construct(
		private ExAppConfigMapper $mapper,
		private LoggerInterface $logger,
		private ICrypto $crypto,
	) {
	}

	public function getAppConfigValues(string $appId, array $configKeys): ?array {
		try {
			return array_map(function (ExAppConfig $exAppConfig) {
				$value = $exAppConfig->getConfigvalue() ?? '';
				if ($value !== '' && $exAppConfig->getSensitive()) {
					try {
						$value = $this->crypto->decrypt($value);
					} catch (\Exception $e) {
						$this->logger->warning(sprintf('Failed to decrypt sensitive value for app %s, config key %s', $exAppConfig->getAppid(), $exAppConfig->getConfigkey()), ['exception' => $e]);
						$value = '';
					}
				}
				return [
					'configkey' => $exAppConfig->getConfigkey(),
					'configvalue' => $value,
				];
			}, $this->mapper->findByAppConfigKeys($appId, $configKeys));
		} catch (Exception) {
			return null;
		}
	}

	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue, ?int $sensitive = null): ?ExAppConfig {
		$appConfigEx = $this->getAppConfig($appId, $configKey);
		if ($configValue !== '' && $sensitive) {
			try {
				$encryptedValue = $this->crypto->encrypt($configValue);
			} catch (\Exception $e) {
				$this->logger->error(sprintf('Failed to encrypt sensitive value for app %s, config key %s. Error: %s', $appId, $configKey, $e->getMessage()), ['exception' => $e]);
				return null;
			}
		} else {
			$encryptedValue = '';
		}
		if ($appConfigEx === null) {
			try {
				$appConfigEx = $this->mapper->insert(new ExAppConfig([
					'appid' => $appId,
					'configkey' => $configKey,
					'configvalue' => $sensitive ? $encryptedValue : $configValue ?? '',
					'sensitive' => $sensitive ?? 0,
				]));
			} catch (Exception $e) {
				$this->logger->error(sprintf('Failed to insert appconfig_ex value. Error: %s', $e->getMessage()), ['exception' => $e]);
				return null;
			}
		} else {
			$appConfigEx->setConfigvalue($sensitive ? $encryptedValue : $configValue);
			if ($sensitive !== null) {
				$appConfigEx->setSensitive($sensitive);
			}
			if ($this->updateAppConfigValue($appConfigEx) === null) {
				$this->logger->error(sprintf('Error while updating appconfig_ex %s value.', $configKey));
				return null;
			}
		}
		if ($sensitive) {
			// setting original unencrypted value for API
			$appConfigEx->setConfigvalue($configValue);
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
