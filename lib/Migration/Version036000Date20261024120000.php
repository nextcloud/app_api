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
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[CreateTable(
	table: 'ex_ui_reference_providers',
	columns: ['id', 'appid', 'provider_id', 'title', 'icon_url', 'order', 'search_providers_ids', 'match_patterns', 'script_path'],
	description: 'Reference providers (Smart Picker entries and link previews) registered by ExApps',
)]
class Version036000Date20261024120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('ex_ui_reference_providers')) {
			return null;
		}

		$table = $schema->createTable('ex_ui_reference_providers');
		$table->addColumn('id', Types::BIGINT, [
			'notnull' => true,
			'autoincrement' => true,
		]);
		$table->addColumn('appid', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		// Provider ID as seen by the server and the Smart Picker; the ExApp's picker script
		// registers its custom element under this same ID.
		$table->addColumn('provider_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('title', Types::STRING, [
			'notnull' => true,
			'length' => 128,
		]);
		$table->addColumn('icon_url', Types::STRING, [
			'notnull' => false,
			'default' => '',
		]);
		$table->addColumn('order', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		// JSON list of unified search provider IDs.
		$table->addColumn('search_providers_ids', Types::TEXT, [
			'notnull' => false,
		]);
		// JSON list of PCRE patterns; a link that matches one of them is resolved by the ExApp.
		$table->addColumn('match_patterns', Types::TEXT, [
			'notnull' => false,
		]);
		// Path of the picker script inside the ExApp; empty when the provider has no custom picker element.
		$table->addColumn('script_path', Types::STRING, [
			'notnull' => false,
			'default' => '',
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['provider_id'], 'ex_ui_ref_prov__provider_id');
		$table->addIndex(['appid'], 'ex_ui_ref_prov__appid');

		return $schema;
	}
}
