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
			'title' => 'AppAPI declarative settings', // NcSettingsSection name
			'description' => 'These fields are rendered dynamically from declarative schema', // NcSettingsSection description
			'fields' => [
				[
					'id' => 'some_real_setting',
					'title' => 'Choose init status check background job interval',
					'description' => 'How often AppAPI should check for initialization status',
					'type' => 'radio', // radio, radio-button (NcCheckboxRadioSwitch button-variant)
					'options' => [
						[
							'name' => 'Each 40 minutes', // NcCheckboxRadioSwitch display name
							'value' => '40m' // NcCheckboxRadioSwitch value
						],
						[
							'name' => 'Each 60 minutes',
							'value' => '60m'
						],
						[
							'name' => 'Each 120 minutes',
							'value' => '120m'
						],
						[
							'name' => 'Each day',
							'value' => 60 * 24 . 'm'
						],
					],
				],
				[
					'id' => 'test_ex_app_field_1', // configkey
					'title' => 'Default text field', // label
					'description' => 'Set some simple text setting', // hint
					'type' => 'text', // text, password, email, tel, url, number
				],
				[
					'id' => 'test_ex_app_field_1_1',
					'title' => 'Email field',
					'description' => 'Set email config',
					'type' => 'email',
				],
				[
					'id' => 'test_ex_app_field_1_2',
					'title' => 'Tel field',
					'description' => 'Set tel config',
					'type' => 'tel',
				],
				[
					'id' => 'test_ex_app_field_1_3',
					'title' => 'Url (website) field',
					'description' => 'Set url config',
					'type' => 'url',
				],
				[
					'id' => 'test_ex_app_field_1_4',
					'title' => 'Number field',
					'description' => 'Set number config',
					'type' => 'number',
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
					'type' => 'select', // select, radio, multi-select
					'options' => ['foo', 'bar', 'baz'],
				],
				[
					'id' => 'test_ex_app_field_4',
					'title' => 'Toggle something',
					'description' => 'Select checkbox option setting',
					'type' => 'checkbox', // checkbox, multiple-checkbox
					'label' => 'Verify something if enabled'
				],
				[
					'id' => 'test_ex_app_field_5',
					'title' => 'Multiple checkbox toggles, describing one setting, checked options are saved as an JSON object {foo: true, bar: false}',
					'description' => 'Select checkbox option setting',
					'type' => 'multi-checkbox', // checkbox, multi-checkbox
					'label' => 'Select multiple toggles',
					'options' => [
						[
							'name' => 'Foo',
							'value' => 'foo',
						],
						[
							'name' => 'Bar',
							'value' => 'bar',
						],
						[
							'name' => 'Baz',
							'value' => 'baz',
						],
						[
							'name' => 'Qux',
							'value' => 'qux',
						],
					],
				],
				[
					'id' => 'test_ex_app_field_6',
					'title' => 'Radio toggles, describing one setting like single select',
					'description' => 'Select radio option setting',
					'type' => 'radio', // radio, radio-button (NcCheckboxRadioSwitch button-variant)
					'label' => 'Select single toggle',
					'options' => [
						[
							'name' => 'First radio', // NcCheckboxRadioSwitch display name
							'value' => 'foo' // NcCheckboxRadioSwitch value
						],
						[
							'name' => 'Second radio',
							'value' => 'bar'
						],
						[
							'name' => 'Second radio',
							'value' => 'baz'
						],
					],
				],
			],
		];
	}
}
