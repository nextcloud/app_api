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

class Version2205Date20240411124836 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('ex_occ_commands')) {
			$table = $schema->createTable('ex_occ_commands');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			// Symfony\Component\Console\Command\Command->setName()
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// Symfony\Component\Console\Command\Command->setDescription()
			$table->addColumn('description', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			// Symfony\Component\Console\Command\Command->setHidden()
			$table->addColumn('hidden', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			// Symfony\Component\Console\Command\Command->addArgument()
			$table->addColumn('arguments', Types::JSON, [
				'notnull' => true,
			]);
			// Symfony\Component\Console\Command\Command->addOption()
			$table->addColumn('options', Types::JSON, [
				'notnull' => true,
			]);
			// Symfony\Component\Console\Command\Command->addUsage()
			$table->addColumn('usages', Types::JSON, [
				'notnull' => true,
			]);
			$table->addColumn('execute_handler', Types::STRING, [
				'notnull' => true,
				'length' => 410,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'name'], 'ex_occ_commands__idx');
		}

		return $schema;
	}
}
