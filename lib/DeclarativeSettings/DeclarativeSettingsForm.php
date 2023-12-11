<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeclarativeSettings;

use OCP\Settings\IDeclarativeSettingsForm;

class DeclarativeSettingsForm implements IDeclarativeSettingsForm {
	public function getSchema(): array {
		return [
			'id' => 'app_api_test_declarative_form',
			'priority' => 10,
			'section_type' => 'admin',
			'section_id' => 'ex_apps_section',
			'storage_type' => 'external',
			'fields' => [
				[
					'id' => 'test_ex_app_field_1',
					'title' => 'Default text field',
					'description' => 'Test settings section',
					'type' => 'string',
				],
				[
					'id' => 'test_ex_app_field_2',
					'title' => 'Password',
					'description' => 'Set some secure value setting',
					'type' => 'password',
				],
				[
					'id' => 'test_ex_app_field_3',
					'title' => 'Selection',
					'description' => 'Select some option setting',
					'type' => 'select',
					'options' => ['foo', 'bar', 'baz'],
				],
			],
		];
	}
}
