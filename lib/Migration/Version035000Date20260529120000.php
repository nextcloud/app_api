<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\Config\IUserConfig;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Backfill AppAPI's legacy `appconfig_ex` / `preferences_ex` rows into the server's standard
 * `IAppConfig` (`oc_appconfig`) and `IUserConfig` (`oc_preferences`) storage.
 *
 * This is the data-move half of the migration; the legacy tables are NOT dropped here (a later
 * migration removes them once the backfill is verified in the field). The refactored
 * {@see \OCA\AppAPI\Service\ExAppConfigService} / {@see \OCA\AppAPI\Service\ExAppPreferenceService}
 * already read/write the standard storage, and migrations run during `occ upgrade` before the new
 * code serves traffic, so there is no dual-write window.
 *
 * Correctness rules:
 *  - Writes go through `setValueString(...)` so the server applies its own encryption envelope
 *    (`$AppConfigEncryption$` / `$UserConfigEncryption$`). A byte copy of legacy sensitive rows
 *    would be unreadable, so we decrypt-then-let-the-server-re-encrypt.
 *  - The legacy `sensitive` column is honored verbatim. DeclarativeSettings sensitive values are
 *    stored by AppAPI as ciphertext with `sensitive = 0` (the listener owns that crypto); those
 *    rows are copied as-is and keep round-tripping through the listener. Do not sniff ciphertext.
 *  - Idempotent: a key already present in the target is skipped (standard storage wins), so a
 *    re-run after a transient failure resumes cleanly. All ExApp values are stored lazy.
 *  - Rows are read in keyset-paginated batches so a large `preferences_ex` cannot OOM `occ upgrade`.
 */
class Version035000Date20260529120000 extends SimpleMigrationStep {

	/**
	 * Number of rows the backfill could not move (e.g. undecryptable sensitive values), persisted
	 * to app config so the follow-up migration that DROPS the legacy tables can refuse to run while
	 * any data is still un-migrated.
	 */
	public const FAILED_FLAG = 'migration_035000_backfill_failed';

	/** Read the legacy tables in keyset-paginated batches of this many rows to bound memory usage. */
	private const BATCH_SIZE = 1000;

	public function __construct(
		private IDBConnection $connection,
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private ICrypto $crypto,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$failed = 0;
		if ($schema->hasTable('appconfig_ex')) {
			$failed += $this->migrateAppConfig($output);
		}
		if ($schema->hasTable('preferences_ex')) {
			$failed += $this->migratePreferences($output);
		}

		// Persist the outcome so the table-drop migration can gate on it. Stored lazy under
		// AppAPI's own app id; a value > 0 means some rows are still only in the legacy tables.
		$this->appConfig->setValueString('app_api', self::FAILED_FLAG, (string)$failed, lazy: true);
		if ($failed > 0) {
			$output->warning(sprintf('Config/preferences backfill left %d row(s) un-migrated — investigate before dropping the legacy tables.', $failed));
		}

		return null;
	}

	private function migrateAppConfig(IOutput $output): int {
		$migrated = $skipped = $failed = 0;

		$lastId = 0;
		while (($rows = $this->fetchBatch('appconfig_ex', ['appid', 'configkey', 'configvalue', 'sensitive'], $lastId)) !== []) {
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				$appId = (string)$row['appid'];
				$configKey = (string)$row['configkey'];
				$sensitive = (int)($row['sensitive'] ?? 0) === 1;

				try {
					if ($this->appConfig->hasKey($appId, $configKey, null)) {
						$skipped++;
						continue;
					}
					$value = $this->decryptLegacyValue((string)($row['configvalue'] ?? ''), $sensitive);
					if ($value === null) {
						$output->warning(sprintf('Config migration: failed to decrypt sensitive value for app %s key %s (row id=%d) — skipping', $appId, $configKey, $lastId));
						$failed++;
						continue;
					}
					$this->appConfig->setValueString($appId, $configKey, $value, lazy: true, sensitive: $sensitive);
					$migrated++;
				} catch (Throwable $e) {
					$output->warning(sprintf('Config migration: failed to migrate app %s key %s (row id=%d): %s — skipping', $appId, $configKey, $lastId, $e->getMessage()));
					$failed++;
				}
			}
		}

		$output->info(sprintf('Config migration (appconfig_ex -> oc_appconfig): %d migrated, %d already present, %d failed', $migrated, $skipped, $failed));
		return $failed;
	}

	private function migratePreferences(IOutput $output): int {
		$migrated = $skipped = $failed = 0;

		$lastId = 0;
		while (($rows = $this->fetchBatch('preferences_ex', ['userid', 'appid', 'configkey', 'configvalue', 'sensitive'], $lastId)) !== []) {
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				$userId = (string)$row['userid'];
				$appId = (string)$row['appid'];
				$configKey = (string)$row['configkey'];
				$sensitive = (int)($row['sensitive'] ?? 0) === 1;

				try {
					if ($this->userConfig->hasKey($userId, $appId, $configKey, null)) {
						$skipped++;
						continue;
					}
					$value = $this->decryptLegacyValue((string)($row['configvalue'] ?? ''), $sensitive);
					if ($value === null) {
						$output->warning(sprintf('Preferences migration: failed to decrypt sensitive value for user %s app %s key %s (row id=%d) — skipping', $userId, $appId, $configKey, $lastId));
						$failed++;
						continue;
					}
					$this->userConfig->setValueString(
						$userId, $appId, $configKey, $value,
						lazy: true,
						flags: $sensitive ? IUserConfig::FLAG_SENSITIVE : 0,
					);
					$migrated++;
				} catch (Throwable $e) {
					$output->warning(sprintf('Preferences migration: failed to migrate user %s app %s key %s (row id=%d): %s — skipping', $userId, $appId, $configKey, $lastId, $e->getMessage()));
					$failed++;
				}
			}
		}

		$output->info(sprintf('Preferences migration (preferences_ex -> oc_preferences): %d migrated, %d already present, %d failed', $migrated, $skipped, $failed));
		return $failed;
	}

	/**
	 * Decrypt a legacy value if it was stored encrypted. Mirrors the old services' guard:
	 * only `sensitive=1` rows with a non-empty value were ever encrypted (with the raw
	 * `ICrypto` envelope, no prefix). Returns the plaintext, or null if decryption failed.
	 */
	private function decryptLegacyValue(string $rawValue, bool $sensitive): ?string {
		if (!$sensitive || $rawValue === '') {
			return $rawValue;
		}
		try {
			return $this->crypto->decrypt($rawValue);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Fetch up to {@see BATCH_SIZE} rows whose id is greater than `$afterId`, ordered by id, then
	 * close the cursor before the caller issues any writes. Keyset pagination keeps memory bounded on
	 * large tables and avoids holding a forward-only cursor open while writing config on the same
	 * connection. The backfill never modifies the legacy tables, so paging over them is stable.
	 *
	 * @param string[] $columns
	 * @return list<array<string,mixed>>
	 */
	private function fetchBatch(string $table, array $columns, int $afterId): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', ...$columns)
			->from($table)
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(self::BATCH_SIZE);
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		return $rows;
	}
}
