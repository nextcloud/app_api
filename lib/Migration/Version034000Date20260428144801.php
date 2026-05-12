<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Move TalkBot bookkeeping out of the generic appconfig_ex K/V store into a dedicated table.
 *
 * Backfill walks the two old appconfig_ex rows per bot — secret keyed by sha1(appid_route),
 * route indexed under 'talk_bot_route_' . sha1(appid_route) — and collapses them into one row.
 * The bot's human-readable name + description remain owned by spreed's talk_bots_server (Talk
 * set them via BotInstallEvent at registration time and remains the source of truth); AppAPI's
 * table holds only what AppAPI needs to authenticate and route inbound bot messages.
 */
#[CreateTable(table: 'ex_apps_talk_bots', columns: ['id', 'appid', 'route', 'secret', 'created_time'], description: 'TalkBot registrations owned by AppAPI')]
class Version034000Date20260428144801 extends SimpleMigrationStep {

	private const TALK_BOT_ROUTE_PREFIX = 'talk_bot_route_';

	public function __construct(
		private IDBConnection $connection,
		private ICrypto $crypto,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ex_apps_talk_bots')) {
			$table = $schema->createTable('ex_apps_talk_bots');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('route', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			// ICrypto envelope output is variable-length; TEXT keeps headroom across DB engines.
			$table->addColumn('secret', Types::TEXT, [
				'notnull' => true,
			]);
			// BIGINT for consistency with oc_ex_apps.created_time and to avoid the year-2038 truncation
			// that affects INT-typed Unix timestamps.
			$table->addColumn('created_time', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'route'], 'ex_apps_talk_bots__app_route');
			$table->addIndex(['appid'], 'ex_apps_talk_bots__appid');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('appconfig_ex') || !$schema->hasTable('ex_apps_talk_bots')) {
			return null;
		}

		// Materialize the cursor up front. Iterating a forward-only cursor while issuing DML
		// against the same table on the same connection is undefined across drivers, and we
		// also want each per-bot insert+deletes to commit (or roll back) as a single unit.
		$rows = $this->fetchRouteIndexRows();

		$migrated = 0;
		$skipped = 0;

		foreach ($rows as $row) {
			$appId = (string)$row['appid'];
			$route = (string)$row['configvalue'];
			$expectedHash = sha1($appId . '_' . $route);
			$keySuffix = substr((string)$row['configkey'], strlen(self::TALK_BOT_ROUTE_PREFIX));

			if ($keySuffix !== $expectedHash) {
				$output->warning(sprintf(
					'TalkBot migration: malformed talk_bot_route row id=%d (appid=%s) — key suffix does not match sha1(appid_route), skipping',
					(int)$row['id'], $appId,
				));
				$skipped++;
				continue;
			}

			$secretRow = $this->fetchSecretRow($appId, $expectedHash);
			if ($secretRow === null) {
				$output->warning(sprintf(
					'TalkBot migration: orphan talk_bot_route_%s for app %s (no matching secret row), skipping',
					$expectedHash, $appId,
				));
				$skipped++;
				continue;
			}

			// Pre-migration TalkBotsService stored bot secrets via ExAppConfigService::setAppConfigValue()
			// WITHOUT passing $sensitive=true, so legacy rows are plaintext (sensitive=0). Honor the column
			// instead of unconditionally decrypting — Version032002Date20250527174907 retroactively encrypted
			// any sensitive=1 rows already, so if someone manually marked a bot secret sensitive after the fact
			// we still handle it correctly.
			$rawSecret = (string)$secretRow['configvalue'];
			if ((int)($secretRow['sensitive'] ?? 0) === 1 && $rawSecret !== '') {
				try {
					$plaintextSecret = $this->crypto->decrypt($rawSecret);
				} catch (Throwable $e) {
					$output->warning(sprintf(
						'TalkBot migration: failed to decrypt sensitive secret for app %s route %s: %s — skipping',
						$appId, $route, $e->getMessage(),
					));
					$skipped++;
					continue;
				}
			} else {
				$plaintextSecret = $rawSecret;
			}

			$this->connection->beginTransaction();
			try {
				if (!$this->talkBotExists($appId, $route)) {
					$this->insertTalkBot($appId, $route, $this->crypto->encrypt($plaintextSecret));
				}
				$this->deleteAppconfigRow((int)$row['id']);
				$this->deleteAppconfigRow((int)$secretRow['id']);
				$this->connection->commit();
				$migrated++;
			} catch (Throwable $e) {
				try {
					$this->connection->rollBack();
				} catch (Throwable) {
					// rollBack failures on an already-aborted transaction are not actionable here.
				}
				$output->warning(sprintf(
					'TalkBot migration: per-bot transaction failed for app %s route %s: %s — old rows preserved, retry by re-running the migration',
					$appId, $route, $e->getMessage(),
				));
				$skipped++;
			}
		}

		$output->info(sprintf('TalkBot migration: %d migrated, %d skipped', $migrated, $skipped));
		return null;
	}

	/**
	 * @return array<array{id:int,appid:string,configkey:string,configvalue:string}>
	 */
	private function fetchRouteIndexRows(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'appid', 'configkey', 'configvalue')
			->from('appconfig_ex')
			->where($qb->expr()->like('configkey', $qb->createNamedParameter(self::TALK_BOT_ROUTE_PREFIX . '%')));
		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		return $rows;
	}

	private function fetchSecretRow(string $appId, string $hash): ?array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'configvalue', 'sensitive')
			->from('appconfig_ex')
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId)),
				$qb->expr()->eq('configkey', $qb->createNamedParameter($hash)),
			)
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row === false ? null : $row;
	}

	private function talkBotExists(string $appId, string $route): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id')
			->from('ex_apps_talk_bots')
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId)),
				$qb->expr()->eq('route', $qb->createNamedParameter($route)),
			)
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$exists = $res->fetch() !== false;
		$res->closeCursor();
		return $exists;
	}

	private function insertTalkBot(string $appId, string $route, string $encryptedSecret): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert('ex_apps_talk_bots')
			->values([
				'appid' => $qb->createNamedParameter($appId),
				'route' => $qb->createNamedParameter($route),
				'secret' => $qb->createNamedParameter($encryptedSecret),
				'created_time' => $qb->createNamedParameter(time(), Types::BIGINT),
			]);
		$qb->executeStatement();
	}

	private function deleteAppconfigRow(int $id): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('appconfig_ex')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, Types::INTEGER)));
		$qb->executeStatement();
	}
}
