<?php

declare(strict_types=1);

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1003Date202311061844 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('ex_apps_ui_top_menu')) {
			$table = $schema->createTable('ex_apps_ui_top_menu');

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
			$table->addColumn('icon_url', Types::STRING, [
				'notnull' => true,
				'default' => '',
			]);
			$table->addColumn('admin_required', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'name'], 'ui_top_menu__idx');
		}

		if (!$schema->hasTable('ex_apps_ui_state')) {
			$table = $schema->createTable('ex_apps_ui_state');

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

		if (!$schema->hasTable('ex_apps_ui_scripts')) {
			$table = $schema->createTable('ex_apps_ui_scripts');

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
				'length' => 2000,
			]);
			$table->addColumn('after_app_id', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'type', 'name'], 'ui_script__idx');
		}

		if (!$schema->hasTable('ex_apps_ui_styles')) {
			$table = $schema->createTable('ex_apps_ui_styles');

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
				'length' => 2000,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'type', 'name'], 'ui_style__idx');
		}

		return $schema;
	}
}
