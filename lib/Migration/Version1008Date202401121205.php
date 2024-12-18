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

class Version1008Date202401121205 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('ex_translation')) {
			$table = $schema->createTable('ex_translation');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('display_name', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('from_languages', Types::JSON, [
				'notnull' => true,
			]);
			$table->addColumn('to_languages', Types::JSON, [
				'notnull' => true,
			]);
			$table->addColumn('action_handler', Types::STRING, [
				'notnull' => true,
				'length' => 410,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'name'], 'ex_translation__idx');
		}

		if (!$schema->hasTable('ex_translation_q')) {
			$table = $schema->createTable('ex_translation_q');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('result', Types::TEXT, [
				'notnull' => true,
				'default' => '',
			]);
			$table->addColumn('error', Types::STRING, [
				'notnull' => true,
				'default' => '',
				'length' => 1024,
			]);
			$table->addColumn('finished', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('created_time', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['finished']);
		}

		return $schema;
	}
}
