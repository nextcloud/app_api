<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\AddColumn;
use OCP\Migration\Attributes\AddIndex;
use OCP\Migration\Attributes\ColumnType;
use OCP\Migration\Attributes\IndexType;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Breaking changes migration refactoring UI tables (renames)
 */
#[AddColumn('ex_deploy_options', 'id', ColumnType::BIGINT, 'advanced deploy options')]
#[AddColumn('ex_deploy_options', 'appid', ColumnType::STRING, 'advanced deploy options')]
#[AddColumn('ex_deploy_options', 'type', ColumnType::STRING, 'advanced deploy options')]
#[AddColumn('ex_deploy_options', 'value', ColumnType::JSON, 'advanced deploy options')]
#[AddIndex('ex_deploy_options', IndexType::UNIQUE, 'advanced deploy options')]
class Version032001Date20250115164140 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('ex_deploy_options')) {
			$table = $schema->createTable('ex_deploy_options');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('type', Types::STRING, [ // environment_variables/mounts/ports
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('value', Types::JSON, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'type'], 'deploy_options__idx');
		}


		return $schema;
	}
}
