<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2204Date20240401104001 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ex_event_handlers')) {
			$table = $schema->createTable('ex_event_handlers');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('event_type', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('event_subtypes', Types::JSON, [
				'notnull' => true,
			]);
			$table->addColumn('action_handler', Types::STRING, [
				'notnull' => true,
				'length' => 410,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'event_type'], 'ex_event_handlers__idx');
		}

		return $schema;
	}
}
