<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version032002Date20250527174907 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('preferences_ex')) {
			$table = $schema->getTable('preferences_ex');

			if (!$table->hasColumn('sensitive')) {
				$table->addColumn('sensitive', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// encrypt appconfig_ex values that have sensitive flag set

		$qbSelect = $this->connection->getQueryBuilder();
		$qbSelect->select(['id', 'configvalue'])
			->from('appconfig_ex')
			->where($qbSelect->expr()->eq('sensitive', $qbSelect->createNamedParameter(1, Types::SMALLINT)));
		$req = $qbSelect->executeQuery();

		while ($row = $req->fetch()) {
			$configValue = $row['configvalue'];
			if (!empty($configValue)) {
				try {
					$encryptedValue = $this->crypto->encrypt($configValue);
					$qbUpdate = $this->connection->getQueryBuilder();
					$qbUpdate->update('appconfig_ex')
						->set('configvalue', $qbUpdate->createNamedParameter($encryptedValue))
						->where(
							$qbUpdate->expr()->eq('id', $qbUpdate->createNamedParameter($row['id'], Types::INTEGER))
						);
					$qbUpdate->executeStatement();
				} catch (\Exception $e) {
					$output->warning(sprintf('Failed to encrypt sensitive value for app config id %s: %s', $row['id'], $e->getMessage()));
				}
			}
		}

		$req->closeCursor();
		return null;
	}
}
