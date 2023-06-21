<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version1000Date202305221555 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('appconfig_ex')) {
			$table = $schema->createTable('appconfig_ex');

			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('configkey', 'string', [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('configvalue', 'string', [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['appid', 'configkey'], 'appconfig_ex_pk');
			$table->addIndex(['configkey'], 'appconfig_ex_configkey');
		}

		if (!$schema->hasTable('ex_apps')) {
			$table = $schema->createTable('ex_apps');

			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('version', 'string', [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 255
			]);
			$table->addColumn('config', 'json', [
				'notnull' => true,
			]);
			$table->addColumn('secret', 'string', [
				'notnull' => true,
				'length' => 128,
			]);
			$table->addColumn('status', 'json', [
				'notnull' => true,
			]);
			$table->addColumn('enabled', 'smallint', [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('created_time', 'bigint', [
				'notnull' => true,
			]);
			$table->addColumn('last_response_time', 'bigint', [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['appid'], 'ex_apps_id__key');
			$table->addIndex(['appid'], 'ex_apps_appid__index');
			$table->addIndex(['name'], 'ex_apps_name__index');
		}

		if (!$schema->hasTable('preferences_ex')) {
			$table = $schema->createTable('preferences_ex');

			$table->addColumn('userid', 'string', [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('configkey', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('configvalue', 'json', [
				'notnull' => true
			]);

			$table->setPrimaryKey(['userid', 'appid', 'configkey'], 'preferences_ex_pk');
			$table->addIndex(['appid'], 'preferences_ex_appid');
			$table->addIndex(['configkey'], 'preferences_ex_configkey');
		}

		if (!$schema->hasTable('ex_files_actions_menu')) {
			$table = $schema->createTable('ex_files_actions_menu');

			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('display_name', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('mime', 'string', [
				'notnull' => true,
				'default' => 'file',
			]);
			// https://nextcloud.github.io/nextcloud-files/enums/Permission.html
			$table->addColumn('permissions', 'string', [
				'notnull' => true,
			]);
			$table->addColumn('order', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('icon', 'string', [
				'notnull' => true,
				'default' => '',
			]);
			$table->addColumn('icon_class', 'string', [
				'notnull' => true,
				'default' => 'icon-app-ecosystem-v2',
			]);
			// Action handler key name, that will be sent to exApp for handling
			$table->addColumn('action_handler', 'string', [
				'notnull' => true,
				'length' => 64,
			]);

			$table->setPrimaryKey(['appid', 'name'], 'ex_files_actions_menu_pk');
			$table->addIndex(['name'], 'ex_files_actions_menu_name');
		}

		if (!$schema->hasTable('ex_apps_users')) {
			$table = $schema->createTable('ex_apps_users');

			$table->addColumn('id', 'integer', [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('userid', 'string', [
				'notnull' => true,
				'length' => 64,
				'default' => '',
			]);

			$table->setPrimaryKey(['id'], 'ex_apps_users_pk');
			$table->addIndex(['appid'], 'ex_apps_users_appid');
		}

		if (!$schema->hasTable('ex_apps_api_scopes')) {
			$table = $schema->createTable('ex_apps_api_scopes');
//			TODO: Add id primary key

			$table->addColumn('api_route', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('scope_group', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['api_route'], 'ex_apps_api_scopes_pk');
			$table->addIndex(['scope_group'], 'ex_apps_api_scopes_scope_group');
		}

		if (!$schema->hasTable('ex_apps_scopes')) {
			$table = $schema->createTable('ex_apps_scopes');

			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('scope_group', 'integer', [
				'notnull' => true,
				'length' => 64,
			]);

			$table->setPrimaryKey(['id'], 'ex_apps_scopes_pk');
			$table->addIndex(['appid'], 'ex_apps_scopes_appid');
			$table->addIndex(['scope_group'], 'ex_apps_scopes_scope');
		}

		return $schema;
	}
}
