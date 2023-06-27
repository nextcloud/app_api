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

			$table->addColumn('id', 'bigint', [
				'notnull' => true,
				'autoincrement' => true,
			]);
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
			$table->addColumn('sensitive', 'smallint', [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);

			$table->setPrimaryKey(['id'], 'appconfig_ex_pk');
			$table->addUniqueIndex(['appid', 'configkey'], 'appconfig_ex__unique');
			$table->addIndex(['configkey'], 'appconfig_ex_configkey');
		}

		if (!$schema->hasTable('ex_apps')) {
			$table = $schema->createTable('ex_apps');

			$table->addColumn('id', 'bigint', [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('version', 'string', [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('daemon_config_id', 'bigint', [
				'default' => 0,
			]);
			$table->addColumn('host', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('port', 'smallint', [
				'notnull' => true,
			]);
			$table->addColumn('secret', 'string', [
				'notnull' => true,
				'length' => 256,
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

			$table->setPrimaryKey(['id'], 'ex_apps_pk');
			$table->addIndex(['appid'], 'ex_apps_appid__index');
			$table->addIndex(['name'], 'ex_apps_name__index');
			$table->addUniqueIndex(['appid'], 'ex_apps_id__key');
			$table->addUniqueIndex(['host', 'port'], 'ex_apps_host_port__unique');
		}

		// Docker daemon or other configurations
		if (!$schema->hasTable('ex_apps_daemons')) {
			$table = $schema->createTable('ex_apps_daemons');

			$table->addColumn('id', 'bigint', [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('accepts_deploy_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('display_name', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('protocol', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('host', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('port', 'smallint', [
				'notnull' => true,
				'default' => 0, // in case of unix socket
			]);
			$table->addColumn('deploy_config', 'json', [
				'default' => '{}',
			]);

			$table->setPrimaryKey(['id'], 'ex_apps_daemons_id__key');
			$table->addUniqueIndex(['host', 'port'], 'daemons_host_port__unique');
		}

		if (!$schema->hasTable('preferences_ex')) {
			$table = $schema->createTable('preferences_ex');

			$table->addColumn('id', 'bigint', [
				'notnull' => true,
				'autoincrement' => true,
			]);
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
			$table->addColumn('configvalue', 'string', [
				'notnull' => true
			]);

			$table->setPrimaryKey(['id'], 'preferences_ex_pk');
			$table->addUniqueIndex(['userid', 'appid', 'configkey'], 'preferences_ex__unique');
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
			$table->addColumn('order', 'bigint', [
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

			$table->addColumn('id', 'bigint', [
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

			$table->addColumn('id', 'bigint', [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('api_route', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('scope_group', 'bigint', [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id'], 'ex_apps_api_scopes_pk');
			$table->addUniqueIndex(['api_route'], 'ex_apps_api_scopes_routes');
			$table->addIndex(['scope_group'], 'ex_apps_api_scopes_scope_group');
		}

		if (!$schema->hasTable('ex_apps_scopes')) {
			$table = $schema->createTable('ex_apps_scopes');

			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('scope_group', 'bigint', [
				'notnull' => true,
				'length' => 64,
			]);

			$table->setPrimaryKey(['id'], 'ex_apps_scopes_pk');
			$table->addUniqueIndex(['appid', 'scope_group'], 'ex_apps_scopes_appid');
		}

		return $schema;
	}
}
