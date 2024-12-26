<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3000Date20240715170800 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ex_apps_routes')) {
			$table = $schema->createTable('ex_apps_routes');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			// regex route on the ExApp side that is being called from Nextcloud,
			// or other origins (in case of public routes)
			$table->addColumn('url', 'string', [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('verb', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('access_level', 'integer', [
				'notnull' => true,
				'default' => 0, // 0 = user, 1 = admin, 2 = public
			]);
			$table->addColumn('headers_to_exclude', 'string', [
				'notnull' => false,
				'length' => 512,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['appid'], 'ex_apps_routes_appid');
		}

		return $schema;
	}
}
