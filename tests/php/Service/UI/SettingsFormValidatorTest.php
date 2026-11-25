<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service\UI;

use OCA\AppAPI\Service\UI\SettingsFormValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsFormValidatorTest extends TestCase {
	private const FORM = [
		'id' => 'form',
		'section_type' => 'personal',
		'section_id' => 'section',
		'title' => 'Form',
		'priority' => 50,
		'storage_type' => 'external',
		'fields' => [[
			'id' => 'field',
			'title' => 'Field',
			'type' => 'text',
			'default' => 'value',
		]],
	];

	// Each case is a form and the reason it is rejected with, or null when it is accepted.
	public static function schemas(): array {
		return [
			// Accepted
			'valid form' => [self::FORM, null],
			'form id after app prefix' => [self::with(['id' => '2_één-form']), null],
			'64-byte personal internal key' => [self::withField(['id' => str_repeat('é', 32), 'storage_type' => 'internal']), null],
			'empty sensitive internal default' => [self::withField(['storage_type' => 'internal', 'sensitive' => true, 'default' => '']), null],
			'empty title' => [self::with(['title' => '']), null],
			'no fields' => [self::with(['fields' => []]), null],
			'field stored internally' => [self::withField(['storage_type' => 'internal']), null],
			'field stored externally' => [self::withField(['storage_type' => 'external']), null],
			'select with plain string options' => [self::withField(['type' => 'select', 'options' => ['first', 'second']]), null],
			'radio with named options' => [self::withField(['type' => 'radio', 'default' => 'first', 'options' => [['name' => 'First', 'value' => 'first']]]), null],

			// Rejected: form
			'no id' => [self::without('id'), 'id must be a non-empty string'],
			'empty id' => [self::with(['id' => '']), 'id must be a non-empty string'],
			'form id with space' => [self::with(['id' => 'two words']), 'id must be usable in a CSS ID selector'],
			'no section id' => [self::without('section_id'), 'section_id must be a non-empty string'],
			'no section type' => [self::without('section_type'), 'section_type must be admin or personal'],
			'unknown section type' => [self::with(['section_type' => 'administrator']), 'section_type must be admin or personal'],
			'no title' => [self::without('title'), 'title must be a string'],
			'no priority' => [self::without('priority'), 'priority must be an integer'],
			'priority as string' => [self::with(['priority' => '50']), 'priority must be an integer'],
			'fields not a list' => [self::with(['fields' => ['first' => self::FORM['fields'][0]]]), 'fields must be a list'],
			'duplicate field id' => [self::with(['fields' => [self::FORM['fields'][0], self::FORM['fields'][0]]]), 'fields[1].id must be unique'],

			// Rejected: field
			'field with empty id' => [self::withField(['id' => '']), 'fields[0].id must be a non-empty string'],
			'65-byte personal internal key' => [self::withField(['id' => str_repeat('é', 32) . 'a', 'storage_type' => 'internal']), 'fields[0].id exceeds the 64-byte limit for personal settings'],
			'non-string sensitive internal default' => [self::withField(['storage_type' => 'internal', 'sensitive' => true, 'default' => false]), 'fields[0].default must be a string for sensitive internal storage'],
			'field without title' => [self::withField(['title' => null]), 'fields[0].title must be a string'],
			'field without default' => [self::withField(['default' => null]), 'fields[0].default must be set and non-null'],
			'unknown field storage' => [self::withField(['storage_type' => 'relay']), 'fields[0].storage_type must be internal or external'],
			'null field storage' => [self::withField(['storage_type' => null]), 'fields[0].storage_type must be internal or external'],
			'unknown field type' => [self::withField(['type' => 'color']), 'fields[0].type is not supported'],
			'select without options' => [self::withField(['type' => 'select']), 'fields[0].options must be a list'],
			'checkbox group with unnamed option' => [self::withField(['type' => 'multi-checkbox', 'default' => '', 'options' => ['first']]), 'fields[0].options requires name/value pairs for multi-checkbox'],
			'radio with unnamed option' => [self::withField(['type' => 'radio', 'default' => 'first', 'options' => ['first']]), 'fields[0].options requires name/value pairs for radio'],
			'select with options that are not a list' => [self::withField(['type' => 'select', 'options' => ['first' => 'second']]), 'fields[0].options must be a list'],
			'option without name' => [self::withField(['type' => 'select', 'options' => [['value' => 'first']]]), 'fields[0].options requires strings or name/value pairs'],
			'sensitive checkbox' => [self::withField(['type' => 'checkbox', 'default' => false, 'sensitive' => true]), 'fields[0].sensitive is supported only for text and password fields'],
		];
	}

	#[DataProvider('schemas')]
	public function testValidates(array $schema, ?string $expectedReason): void {
		self::assertSame($expectedReason, (new SettingsFormValidator())->validate($schema));
	}

	public static function multipleChoiceDefaults(): array {
		return [
			['multi-select', '', true],
			['multi-select', ['first'], true],
			['multi-select', '["first"]', true],
			['multi-select', '{}', false],
			['multi-select', 'first', false],
			['multi-checkbox', '', true],
			['multi-checkbox', ['first' => false], true],
			['multi-checkbox', '{}', true],
			['multi-checkbox', [], false],
			['multi-checkbox', 'false', false],
		];
	}

	#[DataProvider('multipleChoiceDefaults')]
	public function testMultipleChoiceDefault(string $type, mixed $default, bool $accepted): void {
		$schema = self::withField(['type' => $type, 'default' => $default, 'options' => [['name' => 'First', 'value' => 'first']]]);
		self::assertSame($accepted, (new SettingsFormValidator())->validate($schema) === null);
	}

	// The base form with the given properties replaced.
	private static function with(array $properties): array {
		return array_replace(self::FORM, $properties);
	}

	// The base form with the given properties replaced on its first field.
	private static function withField(array $properties): array {
		return self::with(['fields' => [array_replace(self::FORM['fields'][0], $properties)]]);
	}

	// The base form without the given properties.
	private static function without(string ...$keys): array {
		return array_diff_key(self::FORM, array_flip($keys));
	}
}
