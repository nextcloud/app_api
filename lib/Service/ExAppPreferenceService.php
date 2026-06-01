<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExAppPreference;
use OCP\Config\IUserConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * App per-user preferences backed by the server's standard `IUserConfig` storage (`oc_preferences`).
 *
 * Replaces AppAPI's former `preferences_ex` table. Values are stored as lazy strings, scoped
 * per user; sensitive values are encrypted by the server via the `FLAG_SENSITIVE` flag.
 */
readonly class ExAppPreferenceService {

	public function __construct(
		private IUserConfig $userConfig,
		private IDBConnection $connection,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create or update a per-user preference value.
	 *
	 * When `$sensitive` is null the current sensitivity is preserved (or defaults to
	 * non-sensitive for a new key). The returned object always carries the plaintext value.
	 */
	public function setUserConfigValue(string $userId, string $appId, string $configKey, mixed $configValue, ?int $sensitive = null): ?ExAppPreference {
		try {
			$value = (string)($configValue ?? '');
			$currentSensitive = $this->userConfig->hasKey($userId, $appId, $configKey, null)
				&& $this->userConfig->isSensitive($userId, $appId, $configKey, null);
			$targetSensitive = $sensitive !== null ? (bool)$sensitive : $currentSensitive;

			if ($currentSensitive && !$targetSensitive) {
				$this->downgradeToPlain($userId, $appId, $configKey, $value);
			} else {
				$this->userConfig->setValueString(
					$userId, $appId, $configKey, $value,
					lazy: true,
					flags: $targetSensitive ? IUserConfig::FLAG_SENSITIVE : 0,
				);
			}

			return new ExAppPreference([
				'userid' => $userId,
				'appid' => $appId,
				'configkey' => $configKey,
				'configvalue' => $value,
				'sensitive' => $targetSensitive ? 1 : 0,
			]);
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('Failed to set user config value for user %s, app %s, config key %s. Error: %s', $userId, $appId, $configKey, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Return the values of the requested keys that actually exist, in the shape
	 * `[['configkey' => ..., 'configvalue' => ...], ...]`. Sensitive values are returned decrypted.
	 */
	public function getUserConfigValues(string $userId, string $appId, array $configKeys): ?array {
		try {
			$values = [];
			// array_unique: a single SQL `IN (...)` used to dedupe; preserve that so duplicate
			// request keys don't yield duplicate result rows.
			foreach (array_unique(array_map('strval', $configKeys)) as $configKey) {
				// `null` lazy matches keys regardless of their lazy flag (e.g. eager values written
				// through the server-native provisioning_api user-config endpoint).
				if (!$this->userConfig->hasKey($userId, $appId, $configKey, null)) {
					continue;
				}
				try {
					$lazy = $this->userConfig->isLazy($userId, $appId, $configKey);
					$configValue = $this->userConfig->getValueString($userId, $appId, $configKey, '', lazy: $lazy);
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Failed to read value for user %s, app %s, config key %s', $userId, $appId, $configKey), ['exception' => $e]);
					$configValue = '';
				}
				$values[] = [
					'configkey' => $configKey,
					'configvalue' => $configValue,
				];
			}
			return $values;
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Failed to get user config values for user %s, app %s', $userId, $appId), ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Delete the requested keys that exist; returns the number deleted, or -1 on error.
	 */
	public function deleteUserConfigValues(array $configKeys, string $userId, string $appId): int {
		try {
			$deleted = 0;
			foreach ($configKeys as $configKey) {
				$configKey = (string)$configKey;
				if ($this->userConfig->hasKey($userId, $appId, $configKey, null)) {
					$this->userConfig->deleteUserConfig($userId, $appId, $configKey);
					$deleted++;
				}
			}
			return $deleted;
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('Failed to delete user config values for user %s, app %s. Error: %s', $userId, $appId, $e->getMessage()), ['exception' => $e]);
			return -1;
		}
	}

	/**
	 * Turn a currently-sensitive key into a plain value with the new content.
	 *
	 * IUserConfig won't unset sensitivity via setValueString() (it is sticky), and updateSensitive()
	 * refreshes the type cache but not the value cache. So we drop the key and re-create it as a
	 * plain value, which leaves both storage and the in-request cache correct. The two writes run
	 * in a transaction so a failure can never leave the key deleted (the legacy path was atomic).
	 */
	private function downgradeToPlain(string $userId, string $appId, string $configKey, string $value): void {
		$this->connection->beginTransaction();
		try {
			$this->userConfig->deleteUserConfig($userId, $appId, $configKey);
			$this->userConfig->setValueString($userId, $appId, $configKey, $value, lazy: true, flags: 0);
			$this->connection->commit();
		} catch (\Throwable $e) {
			try {
				$this->connection->rollBack();
			} catch (\Throwable) {
				// rollBack on an already-aborted transaction is not actionable here.
			}
			throw $e;
		}
	}
}
