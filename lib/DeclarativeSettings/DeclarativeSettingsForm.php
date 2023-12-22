<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeclarativeSettings;

use OCP\IL10N;
use OCP\Settings\IDeclarativeSettingsForm;
use OCP\Settings\DeclarativeSettingsTypes;

class DeclarativeSettingsForm implements IDeclarativeSettingsForm {
	public function __construct(
		private readonly IL10N $l, // Declarative settings suppose to receive strings already translated
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'app_api_test_declarative_form',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, // admin, personal
			'section_id' => 'ex_apps_section',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL, // external, internal (handled by core to store in appconfig and preferences)
			'title' => $this->l->t('AppAPI declarative settings'), // NcSettingsSection name
			'description' => $this->l->t('These fields are rendered dynamically from declarative schema'), // NcSettingsSection description
			'doc_url' => '', // NcSettingsSection doc_url for documentation or help page, empty string if not needed
			'fields' => [
				[
					'id' => 'test_ex_app_field_7', // configkey
					'title' => $this->l->t('Multi-selection'), // name or label
					'description' => $this->l->t('Select some option setting'), // hint
					'type' => DeclarativeSettingsTypes::MULTI_SELECT, // select, radio, multi-select
					'options' => ['foo', 'bar', 'baz'], // simple options for select, radio, multi-select
					'placeholder' => $this->l->t('Select some multiple options'), // input placeholder
					'default' => ['foo', 'bar'],
				],
				[
					'id' => 'some_real_setting',
					'title' => $this->l->t('Choose init status check background job interval'),
					'description' => $this->l->t('How often AppAPI should check for initialization status'),
					'type' => DeclarativeSettingsTypes::RADIO, // radio (NcCheckboxRadioSwitch type radio)
					'placeholder' => $this->l->t('Choose init status check background job interval'),
					'default' => '40m',
					'options' => [
						[
							'name' => $this->l->t('Each 40 minutes'), // NcCheckboxRadioSwitch display name
							'value' => '40m' // NcCheckboxRadioSwitch value
						],
						[
							'name' => $this->l->t('Each 60 minutes'),
							'value' => '60m'
						],
						[
							'name' => $this->l->t('Each 120 minutes'),
							'value' => '120m'
						],
						[
							'name' => $this->l->t('Each day'),
							'value' => 60 * 24 . 'm'
						],
					],
				],
				[
					'id' => 'test_ex_app_field_1', // configkey
					'title' => $this->l->t('Default text field'), // label
					'description' => $this->l->t('Set some simple text setting'), // hint
					'type' => DeclarativeSettingsTypes::TEXT, // text, password, email, tel, url, number
					'placeholder' => $this->l->t('Enter text setting'), // placeholder
					'default' => 'foo',
				],
				[
					'id' => 'test_ex_app_field_1_1',
					'title' => $this->l->t('Email field'),
					'description' => $this->l->t('Set email config'),
					'type' => DeclarativeSettingsTypes::EMAIL,
					'placeholder' => $this->l->t('Enter email'),
					'default' => '',
				],
				[
					'id' => 'test_ex_app_field_1_2',
					'title' => $this->l->t('Tel field'),
					'description' => $this->l->t('Set tel config'),
					'type' => DeclarativeSettingsTypes::TEL,
					'placeholder' => $this->l->t('Enter your tel'),
					'default' => '',
				],
				[
					'id' => 'test_ex_app_field_1_3',
					'title' => $this->l->t('Url (website) field'),
					'description' => $this->l->t('Set url config'),
					'type' => 'url',
					'placeholder' => $this->l->t('Enter url'),
					'default' => '',
				],
				[
					'id' => 'test_ex_app_field_1_4',
					'title' => $this->l->t('Number field'),
					'description' => $this->l->t('Set number config'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'placeholder' => $this->l->t('Enter number value'),
					'default' => 0,
				],
				[
					'id' => 'test_ex_app_field_2',
					'title' => $this->l->t('Password'),
					'description' => $this->l->t('Set some secure value setting'),
					'type' => 'password',
					'placeholder' => $this->l->t('Set secure value'),
					'default' => '',
				],
				[
					'id' => 'test_ex_app_field_3',
					'title' => $this->l->t('Selection'),
					'description' => $this->l->t('Select some option setting'),
					'type' => DeclarativeSettingsTypes::SELECT, // select, radio, multi-select
					'options' => ['foo', 'bar', 'baz'],
					'placeholder' => $this->l->t('Select some option setting'),
					'default' => 'foo',
				],
				[
					'id' => 'test_ex_app_field_4',
					'title' => $this->l->t('Toggle something'),
					'description' => $this->l->t('Select checkbox option setting'),
					'type' => DeclarativeSettingsTypes::CHECKBOX, // checkbox, multiple-checkbox
					'label' => $this->l->t('Verify something if enabled'),
					'default' => false,
				],
				[
					'id' => 'test_ex_app_field_5',
					'title' => $this->l->t('Multiple checkbox toggles, describing one setting, checked options are saved as an JSON object {foo: true, bar: false}'),
					'description' => $this->l->t('Select checkbox option setting'),
					'type' => DeclarativeSettingsTypes::MULTI_CHECKBOX, // checkbox, multi-checkbox
					'default' => ['foo' => true, 'bar' => true],
					'options' => [
						[
							'name' => $this->l->t('Foo'),
							'value' => 'foo', // multiple-checkbox configkey
						],
						[
							'name' => $this->l->t('Bar'),
							'value' => 'bar',
						],
						[
							'name' => $this->l->t('Baz'),
							'value' => 'baz',
						],
						[
							'name' => $this->l->t('Qux'),
							'value' => 'qux',
						],
					],
				],
				[
					'id' => 'test_ex_app_field_6',
					'title' => $this->l->t('Radio toggles, describing one setting like single select'),
					'description' => $this->l->t('Select radio option setting'),
					'type' => DeclarativeSettingsTypes::RADIO, // radio (NcCheckboxRadioSwitch type radio)
					'label' => $this->l->t('Select single toggle'),
					'default' => 'foo',
					'options' => [
						[
							'name' => $this->l->t('First radio'), // NcCheckboxRadioSwitch display name
							'value' => 'foo' // NcCheckboxRadioSwitch value
						],
						[
							'name' => $this->l->t('Second radio'),
							'value' => 'bar'
						],
						[
							'name' => $this->l->t('Second radio'),
							'value' => 'baz'
						],
					],
				],
			],
		];
	}
}
