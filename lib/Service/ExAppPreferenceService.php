<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExAppPreference;
use OCA\AppAPI\Db\ExAppPreferenceMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * App per-user preferences (preferences_ex)
 */
class ExAppPreferenceService {

	public function __construct(
		private ExAppPreferenceMapper $mapper,
		private LoggerInterface $logger,
		private ICrypto $crypto,
	) {
	}

	public function setUserConfigValue(string $userId, string $appId, string $configKey, mixed $configValue, ?int $sensitive = null): ?ExAppPreference {
		try {
			$exAppPreference = $this->mapper->findByUserIdAppIdKey($userId, $appId, $configKey);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exAppPreference = null;
		}
		if ($configValue !== '' && $sensitive) {
			try {
				$encryptedValue = $this->crypto->encrypt($configValue);
			} catch (\Exception $e) {
				$this->logger->error('Failed to encrypt sensitive value: ' . $e->getMessage(), ['exception' => $e]);
				return null;
			}
		} else {
			$encryptedValue = '';
		}
		if ($exAppPreference === null) {
			try {
				$exAppPreference = $this->mapper->insert(new ExAppPreference([
					'userid' => $userId,
					'appid' => $appId,
					'configkey' => $configKey,
					'configvalue' => $sensitive ? $encryptedValue : $configValue ?? '',
					'sensitive' => $sensitive ?? 0,
				]));
			} catch (Exception $e) {
				$this->logger->error('Error while inserting new config value: ' . $e->getMessage(), ['exception' => $e]);
				return null;
			}
		} else {
			$exAppPreference->setConfigvalue($sensitive ? $encryptedValue : $configValue);
			if ($sensitive !== null) {
				$exAppPreference->setSensitive($sensitive);
			}
			try {
				if ($this->mapper->updateUserConfigValue($exAppPreference) !== 1) {
					$this->logger->error('Error while updating preferences_ex config value');
					return null;
				}
			} catch (Exception $e) {
				$this->logger->error('Error while updating config value: ' . $e->getMessage(), ['exception' => $e]);
				return null;
			}
		}
		if ($sensitive) {
			// setting original unencrypted value for API
			$exAppPreference->setConfigvalue($configValue);
		}
		return $exAppPreference;
	}

	public function getUserConfigValues(string $userId, string $appId, array $configKeys): ?array {
		try {
			return array_map(function (ExAppPreference $exAppPreference) {
				$value = $exAppPreference->getConfigvalue() ?? '';
				if ($value !== '' && $exAppPreference->getSensitive()) {
					try {
						$value = $this->crypto->decrypt($value);
					} catch (\Exception $e) {
						$this->logger->warning(sprintf('Failed to decrypt sensitive value for user %s, app %s, config key %s', $exAppPreference->getUserid(), $exAppPreference->getAppid(), $exAppPreference->getConfigkey()), ['exception' => $e]);
						$value = '';
					}
				}
				return [
					'configkey' => $exAppPreference->getConfigkey(),
					'configvalue' => $value,
				];
			}, $this->mapper->findByUserIdAppIdKeys($userId, $appId, $configKeys));
		} catch (Exception) {
			return null;
		}
	}

	public function deleteUserConfigValues(array $configKeys, string $userId, string $appId): int {
		try {
			return $this->mapper->deleteUserConfigValues($configKeys, $userId, $appId);
		} catch (Exception) {
			return -1;
		}
	}
}
