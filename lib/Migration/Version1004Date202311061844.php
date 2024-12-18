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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Breaking changes migration refactoring UI tables (renames)
 */
class Version1004Date202311061844 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('ex_ui_top_menu')) {
			$table = $schema->createTable('ex_ui_top_menu');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('display_name', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('icon', Types::STRING, [
				'notnull' => false,
				'default' => '',
			]);
			$table->addColumn('admin_required', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'name'], 'ui_top_menu__idx');
		}

		if (!$schema->hasTable('ex_ui_states')) {
			$table = $schema->createTable('ex_ui_states');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('value', Types::JSON, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'type', 'name', 'key'], 'ui_state__idx');
		}

		if (!$schema->hasTable('ex_ui_scripts')) {
			$table = $schema->createTable('ex_ui_scripts');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('path', Types::STRING, [
				'notnull' => true,
				'length' => 410,
			]);
			$table->addColumn('after_app_id', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'type', 'name', 'path'], 'ui_script__idx');
		}

		if (!$schema->hasTable('ex_ui_styles')) {
			$table = $schema->createTable('ex_ui_styles');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('path', Types::STRING, [
				'notnull' => true,
				'length' => 410,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'type', 'name', 'path'], 'ui_style__idx');
		}

		if ($schema->hasTable('ex_files_actions_menu')) {
			$schema->dropTable('ex_files_actions_menu');
		}

		if (!$schema->hasTable('ex_ui_files_actions')) {
			$table = $schema->createTable('ex_ui_files_actions');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
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
			$table->addColumn('mime', Types::TEXT, [
				'notnull' => true,
				'default' => 'file',
			]);
			$table->addColumn('permissions', Types::STRING, [
				'notnull' => true,
			]);
			$table->addColumn('order', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('icon', Types::STRING, [
				'notnull' => false,
				'default' => '',
			]);
			// Action handler key name, that will be sent to exApp for handling
			$table->addColumn('action_handler', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'name'], 'ex_ui_files_actions__idx');
		}

		return $schema;
	}
}
