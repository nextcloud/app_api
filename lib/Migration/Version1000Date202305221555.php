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
use OCP\DB\Types;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version1000Date202305221555 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('appconfig_ex')) {
			$table = $schema->createTable('appconfig_ex');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('configkey', Types::STRING, [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('configvalue', Types::STRING, [
				'notnull' => false,
			]);
			$table->addColumn('sensitive', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'configkey'], 'appconfig_ex__idx');
			$table->addIndex(['configkey'], 'appconfig_ex__configkey');
		}

		if (!$schema->hasTable('ex_apps')) {
			$table = $schema->createTable('ex_apps');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('version', Types::STRING, [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('daemon_config_id', Types::BIGINT, [
				'default' => 0,
			]);
			$table->addColumn('protocol', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('host', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('port', Types::SMALLINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('secret', Types::STRING, [
				'notnull' => true,
				'length' => 256,
			]);
			$table->addColumn('status', Types::JSON, [
				'notnull' => true,
			]);
			$table->addColumn('enabled', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 1,
			]);
			$table->addColumn('created_time', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('last_response_time', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid'], 'ex_apps__appid');
			$table->addUniqueIndex(['daemon_config_id', 'port'], 'ex_apps_c_port__idx');
		}

		// Docker daemon or other configurations
		if (!$schema->hasTable('ex_apps_daemons')) {
			$table = $schema->createTable('ex_apps_daemons');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('accepts_deploy_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('display_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('protocol', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('host', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('port', Types::SMALLINT, [
				'notnull' => true,
				'unsigned' => true,
				'default' => 0, // in case of unix socket
			]);
			$table->addColumn('deploy_config', Types::JSON, [
				'default' => '{}',
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['host', 'port'], 'ex_apps_daemons__idx');
		}

		if (!$schema->hasTable('preferences_ex')) {
			$table = $schema->createTable('preferences_ex');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('userid', Types::STRING, [
				'notnull' => true,
				'length' => 64
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32
			]);
			$table->addColumn('configkey', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('configvalue', Types::STRING, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['userid', 'appid', 'configkey'], 'preferences_ex__idx');
			$table->addIndex(['configkey'], 'preferences_ex__configkey');
		}

		if (!$schema->hasTable('ex_files_actions_menu')) {
			$table = $schema->createTable('ex_files_actions_menu');

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
			$table->addColumn('mime', Types::STRING, [
				'notnull' => true,
				'default' => 'file',
			]);
			// https://nextcloud.github.io/nextcloud-files/enums/Permission.html
			$table->addColumn('permissions', Types::STRING, [
				'notnull' => true,
			]);
			$table->addColumn('order', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('icon', Types::STRING, [
				'notnull' => true,
				'default' => '',
			]);
			$table->addColumn('icon_class', Types::STRING, [
				'notnull' => true,
				'default' => 'icon-app-ecosystem-v2',
			]);
			// Action handler key name, that will be sent to exApp for handling
			$table->addColumn('action_handler', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'name'], 'ex_files_actions_menu__idx');
			$table->addIndex(['name'], 'ex_files_actions_menu__name');
		}

		if (!$schema->hasTable('ex_apps_users')) {
			$table = $schema->createTable('ex_apps_users');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('userid', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['appid'], 'ex_apps_users__appid');
			$table->addIndex(['appid', 'userid'], 'ex_apps_users__idx');
		}

		if (!$schema->hasTable('ex_apps_api_scopes')) {
			$table = $schema->createTable('ex_apps_api_scopes');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('api_route', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('scope_group', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['api_route'], 'ex_apps_api_scopes__idx');
			$table->addIndex(['scope_group'], 'ex_apps_api_scopes__idx2');
		}

		if (!$schema->hasTable('ex_apps_scopes')) {
			$table = $schema->createTable('ex_apps_scopes');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appid', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('scope_group', Types::BIGINT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid', 'scope_group'], 'ex_apps_scopes__idx');
		}

		return $schema;
	}
}
