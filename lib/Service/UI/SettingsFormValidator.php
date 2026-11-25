<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use OCP\Settings\DeclarativeSettingsTypes as Types;

/**
 * Check ExApp schemas both before storage and when loading previously stored forms.
 * Registration in Core alone does not check everything needed later: sorting uses
 * the priority, and AppAPI needs a non-null default when no value has been saved.
 */
class SettingsFormValidator {
	private const CHOICE_TYPES = [Types::SELECT, Types::MULTI_SELECT, Types::RADIO, Types::MULTI_CHECKBOX];
	private const INPUT_TYPES = [Types::TEXT, Types::PASSWORD, Types::EMAIL, Types::TEL, Types::URL, Types::NUMBER, Types::CHECKBOX];

	/** Return a diagnostic without including any submitted field values. */
	public function validate(array $schema): ?string {
		foreach (['id', 'section_id'] as $key) {
			if (!is_string($schema[$key] ?? null) || $schema[$key] === '') {
				return "$key must be a non-empty string";
			}
		}
		// Core uses the form ID after an app prefix in an unescaped CSS ID selector.
		if (preg_match('/\A[a-zA-Z0-9_\-\x{0080}-\x{10FFFF}]+\z/u', $schema['id']) !== 1) {
			return 'id must be usable in a CSS ID selector';
		}
		if (!is_string($schema['title'] ?? null)) {
			return 'title must be a string';
		}
		if (!in_array($schema['section_type'] ?? null, [Types::SECTION_TYPE_ADMIN, Types::SECTION_TYPE_PERSONAL], true)) {
			return 'section_type must be admin or personal';
		}
		if (!is_int($schema['priority'] ?? null)) {
			return 'priority must be an integer';
		}
		if (!is_array($schema['fields'] ?? null) || !array_is_list($schema['fields'])) {
			return 'fields must be a list';
		}
		$ids = [];
		foreach ($schema['fields'] as $index => $field) {
			if (!is_array($field) || !is_string($field['id'] ?? null) || $field['id'] === '') {
				return "fields[$index].id must be a non-empty string";
			}
			if (isset($ids[$field['id']])) {
				return "fields[$index].id must be unique";
			}
			$ids[$field['id']] = true;
			if (!is_string($field['title'] ?? null)) {
				return "fields[$index].title must be a string";
			}
			if (!isset($field['default'])) {
				return "fields[$index].default must be set and non-null";
			}
			if (array_key_exists('storage_type', $field) && !in_array($field['storage_type'], [Types::STORAGE_TYPE_INTERNAL, Types::STORAGE_TYPE_EXTERNAL], true)) {
				return "fields[$index].storage_type must be internal or external";
			}
			if (($field['storage_type'] ?? null) === Types::STORAGE_TYPE_INTERNAL) {
				if ($schema['section_type'] === Types::SECTION_TYPE_PERSONAL && strlen($field['id']) > 64) {
					return "fields[$index].id exceeds the 64-byte limit for personal settings";
				}
				if (($field['sensitive'] ?? false) === true && !is_string($field['default'])) {
					return "fields[$index].default must be a string for sensitive internal storage";
				}
			}
			$type = $field['type'] ?? null;
			if (($type === Types::MULTI_SELECT || $type === Types::MULTI_CHECKBOX) && !$this->hasUsableChoiceDefault($type, $field['default'])) {
				return $type === Types::MULTI_SELECT
					? "fields[$index].default must represent a list or be an empty string"
					: "fields[$index].default must represent an object or be an empty string";
			}
			if (in_array($type, self::CHOICE_TYPES, true)) {
				if (!is_array($field['options'] ?? null) || !array_is_list($field['options'])) {
					return "fields[$index].options must be a list";
				}
				$requiresNamedOptions = $type === Types::MULTI_CHECKBOX || $type === Types::RADIO;
				foreach ($field['options'] as $option) {
					if (!$requiresNamedOptions && is_string($option)) {
						continue;
					}
					if (!is_array($option) || !is_string($option['name'] ?? null) || !array_key_exists('value', $option)) {
						return $requiresNamedOptions
							? "fields[$index].options requires name/value pairs for $type"
							: "fields[$index].options requires strings or name/value pairs";
					}
				}
			} elseif (!in_array($type, self::INPUT_TYPES, true)) {
				return "fields[$index].type is not supported";
			}
			if (($field['sensitive'] ?? false) === true && !in_array($type, [Types::TEXT, Types::PASSWORD], true)) {
				return "fields[$index].sensitive is supported only for text and password fields";
			}
		}
		return null;
	}

	private function hasUsableChoiceDefault(string $type, mixed $default): bool {
		// The controls initialize an empty selection themselves for this value.
		if ($default === '') {
			return true;
		}
		if (is_array($default)) {
			// GetValueListener encodes PHP lists as JSON arrays and maps as JSON objects.
			return array_is_list($default) === ($type === Types::MULTI_SELECT);
		}
		if (!is_string($default)) {
			return false;
		}
		// A client may already have encoded the default. Preserve the distinction between [] and {}.
		$decoded = json_decode($default);
		return $type === Types::MULTI_SELECT ? is_array($decoded) : $decoded instanceof \stdClass;
	}
}
