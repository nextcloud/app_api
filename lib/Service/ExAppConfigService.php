<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExAppConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * App configuration backed by the server's standard `IAppConfig` storage (`oc_appconfig`).
 *
 * AppAPI historically kept its own `appconfig_ex` table; since the server gained typed,
 * lazy and sensitive (encrypted) app config, that duplication is gone. All ExApp config
 * values are stored as lazy strings; sensitive values are encrypted by the server.
 */
readonly class ExAppConfigService {

	public function __construct(
		private IAppConfig $appConfig,
		private IDBConnection $connection,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Return the values of the requested keys that actually exist, in the shape
	 * `[['configkey' => ..., 'configvalue' => ...], ...]`. Sensitive values are
	 * returned decrypted (the server handles decryption transparently).
	 */
	public function getAppConfigValues(string $appId, array $configKeys): ?array {
		try {
			$values = [];
			// array_unique: a single SQL `IN (...)` used to dedupe; preserve that so duplicate
			// request keys don't yield duplicate result rows.
			foreach (array_unique(array_map('strval', $configKeys)) as $configKey) {
				// `null` lazy matches keys regardless of their lazy flag.
				if (!$this->appConfig->hasKey($appId, $configKey, null)) {
					continue;
				}
				try {
					$configValue = $this->buildConfigValue($appId, $configKey)->getConfigvalue();
				} catch (\Throwable $e) {
					$this->logger->warning(sprintf('Failed to read value for app %s, config key %s', $appId, $configKey), ['exception' => $e]);
					$configValue = '';
				}
				$values[] = [
					'configkey' => $configKey,
					'configvalue' => $configValue,
				];
			}
			return $values;
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Failed to get app config values for app %s', $appId), ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Create or update an ExApp config value.
	 *
	 * When `$sensitive` is null the current sensitivity is preserved (or defaults to
	 * non-sensitive for a new key). The returned object always carries the plaintext value.
	 */
	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue, ?int $sensitive = null): ?ExAppConfig {
		try {
			$value = (string)($configValue ?? '');
			$currentSensitive = $this->appConfig->hasKey($appId, $configKey, null)
				&& $this->appConfig->isSensitive($appId, $configKey, null);
			$targetSensitive = $sensitive !== null ? (bool)$sensitive : $currentSensitive;

			if ($currentSensitive && !$targetSensitive) {
				$this->downgradeToPlain($appId, $configKey, $value);
			} else {
				$this->appConfig->setValueString($appId, $configKey, $value, lazy: true, sensitive: $targetSensitive);
			}

			return new ExAppConfig([
				'appid' => $appId,
				'configkey' => $configKey,
				'configvalue' => $value,
				'sensitive' => $targetSensitive ? 1 : 0,
			]);
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('Failed to set app config value for app %s, config key %s. Error: %s', $appId, $configKey, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Delete the requested keys that exist; returns the number deleted, or -1 on error.
	 */
	public function deleteAppConfigValues(array $configKeys, string $appId): int {
		try {
			$deleted = 0;
			foreach ($configKeys as $configKey) {
				$configKey = (string)$configKey;
				if ($this->appConfig->hasKey($appId, $configKey, null)) {
					$this->appConfig->deleteKey($appId, $configKey);
					$deleted++;
				}
			}
			return $deleted;
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('Failed to delete app config values for app %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]);
			return -1;
		}
	}

	/**
	 * @return ExAppConfig[]
	 */
	public function getAllAppConfig(string $appId): array {
		try {
			$result = [];
			foreach ($this->appConfig->getKeys($appId) as $configKey) {
				try {
					$result[] = $this->buildConfigValue($appId, $configKey);
				} catch (\Throwable $e) {
					// A single unreadable/type-conflicting key must not drop the whole listing.
					$this->logger->warning(sprintf('Failed to read app config for app %s, config key %s — skipping', $appId, $configKey), ['exception' => $e]);
				}
			}
			return $result;
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Failed to list app config for app %s', $appId), ['exception' => $e]);
			return [];
		}
	}

	public function getAppConfig(string $appId, string $configKey): ?ExAppConfig {
		try {
			if (!$this->appConfig->hasKey($appId, $configKey, null)) {
				return null;
			}
			return $this->buildConfigValue($appId, $configKey);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Failed to get app config for app %s, config key %s', $appId, $configKey), ['exception' => $e]);
			return null;
		}
	}

	public function updateAppConfigValue(ExAppConfig $exAppConfig): ?ExAppConfig {
		return $this->setAppConfigValue(
			$exAppConfig->getAppid(),
			$exAppConfig->getConfigkey(),
			$exAppConfig->getConfigvalue(),
			$exAppConfig->getSensitive(),
		);
	}

	public function deleteAppConfig(ExAppConfig $exAppConfig): ?int {
		try {
			$appId = $exAppConfig->getAppid();
			$configKey = $exAppConfig->getConfigkey();
			if (!$this->appConfig->hasKey($appId, $configKey, null)) {
				return 0;
			}
			$this->appConfig->deleteKey($appId, $configKey);
			return 1;
		} catch (\Throwable $e) {
			$this->logger->error(sprintf('Failed to delete app config for app %s, config key %s. Error: %s', $exAppConfig->getAppid(), $exAppConfig->getConfigkey(), $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Turn a currently-sensitive key into a plain value with the new content.
	 *
	 * IAppConfig won't unset sensitivity via setValueString() (it is sticky), and updateSensitive()
	 * refreshes the type cache but not the value cache. So we drop the key and re-create it as a
	 * plain value, which leaves both storage and the in-request cache correct. The two writes run
	 * in a transaction so a failure can never leave the key deleted (the legacy path was atomic).
	 */
	private function downgradeToPlain(string $appId, string $configKey, string $value): void {
		$this->connection->beginTransaction();
		try {
			$this->appConfig->deleteKey($appId, $configKey);
			$this->appConfig->setValueString($appId, $configKey, $value, lazy: true, sensitive: false);
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

	/**
	 * Build the value object for an existing key, reading it with its actual lazy flag
	 * so values created outside AppAPI (e.g. `occ config:app:set`) are handled too.
	 */
	private function buildConfigValue(string $appId, string $configKey): ExAppConfig {
		$lazy = $this->appConfig->isLazy($appId, $configKey);
		return new ExAppConfig([
			'appid' => $appId,
			'configkey' => $configKey,
			'configvalue' => $this->appConfig->getValueString($appId, $configKey, '', lazy: $lazy),
			'sensitive' => $this->appConfig->isSensitive($appId, $configKey, $lazy) ? 1 : 0,
		]);
	}
}
