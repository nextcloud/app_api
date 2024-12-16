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

class Version1000Date202305221555 extends SimpleMigrationStep {
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
			$table->addColumn('configvalue', Types::TEXT, [
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
			$table->addColumn('daemon_config_name', Types::STRING, [
				'default' => 0,
				'length' => 64,
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
			$table->addColumn('last_check_time', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['appid'], 'ex_apps__appid');
			$table->addUniqueIndex(['host', 'port'], 'ex_apps_c_port__idx');
		}

		// Docker daemon or other configurations
		if (!$schema->hasTable('ex_apps_daemons')) {
			$table = $schema->createTable('ex_apps_daemons');

			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('display_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('accepts_deploy_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('protocol', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('host', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('deploy_config', Types::JSON, [
				'default' => null,
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name'], 'ex_apps_daemons__name');
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
			$table->addColumn('configvalue', Types::TEXT, [
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
			$table->addColumn('icon_class', Types::STRING, [
				'notnull' => true,
				'default' => 'icon-app-api',
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
			$table->addColumn('user_check', Types::SMALLINT, [
				'notnull' => true,
				'default' => 1,
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
