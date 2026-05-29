<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\Attributes\DropTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop the legacy `appconfig_ex` / `preferences_ex` tables, now that
 * {@see Version035000Date20260529120000} has backfilled their contents into the server's standard
 * `oc_appconfig` / `oc_preferences`.
 *
 * Runs strictly after the backfill (later timestamp). It only drops when it is safe to do so —
 * see {@see self::backfillIsComplete()} — so that running the drop without (or before) a successful
 * backfill can never silently destroy un-migrated data.
 */
#[DropTable(table: 'appconfig_ex')]
#[DropTable(table: 'preferences_ex')]
class Version035000Date20260529130000 extends SimpleMigrationStep {

	private const LEGACY_TABLES = ['appconfig_ex', 'preferences_ex'];

	private bool $dropped = false;

	public function __construct(
		private IAppConfig $appConfig,
		private IDBConnection $connection,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		if (!$this->backfillIsComplete($output)) {
			return null;
		}

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		foreach (self::LEGACY_TABLES as $table) {
			if ($schema->hasTable($table)) {
				$schema->dropTable($table);
				$this->dropped = true;
			}
		}

		return $this->dropped ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// The outcome flag only existed to gate this drop; remove it once the tables are gone.
		if ($this->dropped && $this->appConfig->hasKey('app_api', Version035000Date20260529120000::FAILED_FLAG, null)) {
			$this->appConfig->deleteKey('app_api', Version035000Date20260529120000::FAILED_FLAG);
		}
		return null;
	}

	/**
	 * Decide whether it is safe to drop the legacy tables. The backfill records its outcome in the
	 * app config key `app_api / <FAILED_FLAG>`:
	 *  - flag present and 0  -> backfill ran cleanly, safe to drop;
	 *  - flag present and >0 -> some rows failed to migrate, keep the legacy tables as a recoverable copy;
	 *  - flag absent         -> the backfill has not recorded an outcome. That is normal on a fresh,
	 *                           schema-only install (where the legacy tables never hold data), but it
	 *                           also happens if this migration is run out of order / before the backfill.
	 *                           In that case we only drop when the legacy tables hold no data to lose.
	 */
	private function backfillIsComplete(IOutput $output): bool {
		if ($this->appConfig->hasKey('app_api', Version035000Date20260529120000::FAILED_FLAG, null)) {
			$failed = (int)$this->appConfig->getValueString('app_api', Version035000Date20260529120000::FAILED_FLAG, '0', lazy: true);
			if ($failed > 0) {
				$output->warning(sprintf(
					'Keeping appconfig_ex/preferences_ex: %d row(s) failed to backfill into oc_appconfig/oc_preferences. '
					. 'Resolve them, then re-run the upgrade to drop the legacy tables.',
					$failed,
				));
				return false;
			}
			return true;
		}

		foreach (self::LEGACY_TABLES as $table) {
			if ($this->connection->tableExists($table) && $this->tableHasRows($table)) {
				$output->warning(sprintf(
					'Keeping %s: the config backfill (Version035000Date20260529120000) has not run yet. '
					. 'Run "occ upgrade" so the data is migrated before the legacy tables are dropped.',
					$table,
				));
				return false;
			}
		}
		return true;
	}

	private function tableHasRows(string $table): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count > 0;
	}
}
