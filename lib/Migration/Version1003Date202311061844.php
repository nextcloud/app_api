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

		if (!$schema->hasTable('menu_entries_ex')) {
			$table = $schema->createTable('menu_entries_ex');

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
			$table->addColumn('route', Types::STRING, [
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
			$table->addUniqueIndex(['appid', 'name'], 'menu_entries_ex__idx');
		}

		return $schema;
	}
}
