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

class Version2000Date20240120094952 extends SimpleMigrationStep {
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

		$table = $schema->getTable('ex_apps');
		if ($table->hasColumn('protocol')) {
			$table->dropColumn('protocol');
		}
		if ($table->hasColumn('host')) {
			$table->dropColumn('host');
		}
		$table->dropIndex('ex_apps_c_port__idx');
		$table->addUniqueIndex(['daemon_config_name', 'port'], 'ex_apps_c_port__idx');

		$table = $schema->getTable('ex_apps_daemons');
		$table->changeColumn('deploy_config', [
			'notnull' => true,
		]);

		return $schema;
	}
}
