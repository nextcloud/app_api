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

/**
 * Since AppAPI 3.0.0, the `system` flag of the ExApp has been removed.
 */
class Version2800Date20240710220000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ex_apps')) {
			$table = $schema->getTable('ex_apps');
			if ($table->hasColumn('is_system')) {
				$table->dropColumn('is_system');
			}
		}

		if ($schema->hasTable('ex_apps_users')) {
			$schema->dropTable('ex_apps_users');
		}

		return $schema;
	}
}
