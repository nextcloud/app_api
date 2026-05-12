<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Migration;

use OCA\AppAPI\Migration\Version034000Date20260428144801;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Migration backfill coverage. The earlier 263-line version of this test was removed (commit
 * 26c33e3) as "one-shot"; this slimmer version stays in tree because the plaintext-secret
 * path was a real regression that escaped review the first time around. Every scenario here
 * is one we want CI to keep catching: plaintext secret round-trip, sensitive=1 decrypt-then-
 * re-encrypt, orphan rows, malformed-key skip, multi-bot, and idempotent re-run.
 *
 * Tests insert directly into appconfig_ex (legacy K/V shape) and assert that the migration
 * collapses the pair into the new dedicated row + leaves no residue.
 */
#[Group('DB')]
class Version034000Date20260428144801Test extends TestCase {

	private const TEST_APP_ONE = 'phpunit_migration_botapp1';
	private const TEST_APP_TWO = 'phpunit_migration_botapp2';
	private const TALK_BOT_ROUTE_PREFIX = 'talk_bot_route_';

	private IDBConnection $db;
	private ICrypto $crypto;
	private Version034000Date20260428144801 $migration;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->crypto = Server::get(ICrypto::class);
		$this->migration = new Version034000Date20260428144801(
			$this->db,
			$this->crypto,
		);
		$this->cleanupAll();
	}

	protected function tearDown(): void {
		$this->cleanupAll();
		parent::tearDown();
	}

	public function testPlaintextSecretMigratesAndRoundTrips(): void {
		// Legacy reality: TalkBotsService::setAppConfigValue() was called without $sensitive=true,
		// so bot secrets sit in appconfig_ex as plaintext. The migration must accept this and
		// re-encrypt on insert into the new table.
		$plaintext = str_repeat('A', 64);
		$this->seedLegacyBot(self::TEST_APP_ONE, 'plain_route', $plaintext, sensitive: 0);

		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);

		$row = $this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'plain_route');
		self::assertNotNull($row, 'bot row must exist post-migration');
		self::assertSame($plaintext, $this->crypto->decrypt($row['secret']));
		self::assertSame(0, $this->countLegacyRowsFor(self::TEST_APP_ONE), 'legacy rows must be purged');
	}

	public function testSensitiveEncryptedSecretDecryptsThenReEncrypts(): void {
		// Hypothetical scenario: an operator manually flipped sensitive=1 and let Version032002
		// re-encrypt the row in place. The migration's `if (sensitive == 1)` branch must decrypt
		// with the same key and re-encrypt for the new table.
		$plaintext = str_repeat('B', 64);
		$encryptedLegacy = $this->crypto->encrypt($plaintext);
		$this->seedLegacyBot(self::TEST_APP_ONE, 'sensitive_route', $encryptedLegacy, sensitive: 1);

		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);

		$row = $this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'sensitive_route');
		self::assertNotNull($row);
		self::assertSame($plaintext, $this->crypto->decrypt($row['secret']));
	}

	public function testOrphanRouteRowSkippedAndPreserved(): void {
		// A route row without its matching secret row: defensive skip, no insert, no delete —
		// re-running the migration after the operator fixes the data must still succeed.
		$route = 'orphan_route';
		$hash = sha1(self::TEST_APP_ONE . '_' . $route);
		$this->insertAppConfigEx(self::TEST_APP_ONE, self::TALK_BOT_ROUTE_PREFIX . $hash, $route, 0);

		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);

		self::assertNull($this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, $route));
		self::assertSame(1, $this->countLegacyRowsFor(self::TEST_APP_ONE), 'orphan route must NOT be deleted');
	}

	public function testMalformedKeySuffixSkipped(): void {
		// A talk_bot_route_* row whose suffix does not match sha1(appid_route) is either corrupt
		// or planted by an external writer. Don't trust the configvalue; skip and preserve.
		$route = 'mismatched_route';
		$this->insertAppConfigEx(self::TEST_APP_ONE, self::TALK_BOT_ROUTE_PREFIX . str_repeat('0', 40), $route, 0);
		// Add a (different-key) secret row so we can prove the migration didn't consume it either.
		$this->insertAppConfigEx(self::TEST_APP_ONE, str_repeat('0', 40), str_repeat('X', 64), 0);

		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);

		self::assertNull($this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, $route));
		self::assertSame(2, $this->countLegacyRowsFor(self::TEST_APP_ONE), 'malformed pair must be preserved as-is');
	}

	public function testMultiBotMultiApp(): void {
		// Three bots across two ExApps in a single migration pass. The unique (appid, route)
		// constraint means each pair lands in its own row, even when routes collide across apps.
		$this->seedLegacyBot(self::TEST_APP_ONE, 'shared_route', str_repeat('A', 64), 0);
		$this->seedLegacyBot(self::TEST_APP_ONE, 'second_route', str_repeat('B', 64), 0);
		$this->seedLegacyBot(self::TEST_APP_TWO, 'shared_route', str_repeat('C', 64), 0);

		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);

		self::assertNotNull($this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'shared_route'));
		self::assertNotNull($this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'second_route'));
		self::assertNotNull($this->fetchExAppsTalkBotsRow(self::TEST_APP_TWO, 'shared_route'));
		self::assertSame(0, $this->countLegacyRowsFor(self::TEST_APP_ONE));
		self::assertSame(0, $this->countLegacyRowsFor(self::TEST_APP_TWO));
	}

	public function testIdempotentRerun(): void {
		// `occ migrations:execute` is the operator's escape hatch when a previous run was
		// interrupted. Running the migration twice on the same data must not duplicate rows
		// or fail; the second pass should see 0 candidate route rows.
		$this->seedLegacyBot(self::TEST_APP_ONE, 'idempotent_route', str_repeat('D', 64), 0);
		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);
		$first = $this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'idempotent_route');

		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);
		$second = $this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'idempotent_route');

		self::assertNotNull($first);
		self::assertNotNull($second);
		self::assertSame((int)$first['id'], (int)$second['id'], 'idempotent re-run must NOT mint a new row');
	}

	public function testRerunAfterPreviousMigratedRow(): void {
		// Simulates: operator re-runs after fixing the data — the previously-migrated bot is
		// still in ex_apps_talk_bots, AND a fresh legacy pair shows up for the SAME route.
		// The migration must skip the insert (UNIQUE constraint would fire), still clean the
		// stale legacy pair, and not corrupt the existing row.
		$this->seedLegacyBot(self::TEST_APP_ONE, 'preexist_route', str_repeat('E', 64), 0);
		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);
		$beforeId = (int)$this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'preexist_route')['id'];

		// Re-seed the legacy pair (e.g. someone restored from a partial backup).
		$this->seedLegacyBot(self::TEST_APP_ONE, 'preexist_route', str_repeat('F', 64), 0);
		$this->migration->postSchemaChange(new \OC\Migration\NullOutput(), fn () => $this->schema(), []);

		$row = $this->fetchExAppsTalkBotsRow(self::TEST_APP_ONE, 'preexist_route');
		self::assertSame($beforeId, (int)$row['id'], 'pre-existing row must be untouched');
		self::assertSame(0, $this->countLegacyRowsFor(self::TEST_APP_ONE), 'stale legacy pair must still be cleaned');
	}

	private function seedLegacyBot(string $appId, string $route, string $secretValue, int $sensitive): void {
		$hash = sha1($appId . '_' . $route);
		$this->insertAppConfigEx($appId, $hash, $secretValue, $sensitive);
		$this->insertAppConfigEx($appId, self::TALK_BOT_ROUTE_PREFIX . $hash, $route, 0);
	}

	private function insertAppConfigEx(string $appId, string $configKey, string $configValue, int $sensitive): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('appconfig_ex')
			->values([
				'appid' => $qb->createNamedParameter($appId),
				'configkey' => $qb->createNamedParameter($configKey),
				'configvalue' => $qb->createNamedParameter($configValue),
				'sensitive' => $qb->createNamedParameter($sensitive, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	private function fetchExAppsTalkBotsRow(string $appId, string $route): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('ex_apps_talk_bots')
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId)),
				$qb->expr()->eq('route', $qb->createNamedParameter($route)),
			)
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row === false ? null : $row;
	}

	private function countLegacyRowsFor(string $appId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('appconfig_ex')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return (int)$row['cnt'];
	}

	private function cleanupAll(): void {
		foreach ([self::TEST_APP_ONE, self::TEST_APP_TWO] as $appId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('appconfig_ex')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId)));
			$qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete('ex_apps_talk_bots')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId)));
			$qb->executeStatement();
		}
	}

	private function schema(): ISchemaWrapper {
		// Server::get(IDBConnection::class) returns the public ConnectionAdapter, but SchemaWrapper
		// needs the internal OC\DB\Connection it wraps. Reflect once at test setup time — the
		// `inner` field on ConnectionAdapter is declared private but stable across NC versions.
		$inner = (new \ReflectionProperty(\OC\DB\ConnectionAdapter::class, 'inner'))->getValue($this->db);
		return new \OC\DB\SchemaWrapper($inner);
	}
}
