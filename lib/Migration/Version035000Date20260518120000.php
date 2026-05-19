<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\AddColumn;
use OCP\Migration\Attributes\ColumnType;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[AddColumn('ex_ui_files_actions', 'default_action', ColumnType::STRING, 'DefaultType for ExApp file actions')]
class Version035000Date20260518120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ex_ui_files_actions')) {
			$table = $schema->getTable('ex_ui_files_actions');

			if (!$table->hasColumn('default_action')) {
				$table->addColumn('default_action', Types::STRING, [
					'notnull' => false,
					'length' => 16,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
