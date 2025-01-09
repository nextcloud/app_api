<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version032000Date20250109162434 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ex_task_processing')) {
			$table = $schema->getTable('ex_task_processing');

			$name = $table->getColumn('name');
			if ($name->getLength() < 255) {
				$name->setLength(255);
			}

			$displayName = $table->getColumn('display_name');
			if ($displayName->getLength() < 255) {
				$displayName->setLength(255);
			}

			$taskType = $table->getColumn('task_type');
			if ($taskType->getLength() < 255) {
				$taskType->setLength(255);
			}
		}

		return $schema;
	}
}
