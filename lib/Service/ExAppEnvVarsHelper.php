<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use InvalidArgumentException;

/**
 * Normalize declared ExApp environment variables from info.xml before they are deployed and persisted.
 *
 * The manifest is parsed with a simplexml/json roundtrip, where an empty element
 * (`<default></default>` or `<default/>`) becomes an empty array instead of an empty string.
 * Left as-is, such a value passes the "drop variables with an empty value" filter and is later
 * stringified to the literal `Array` in the container environment.
 *
 * The helper produces a canonical NAME => {name, displayName, description, default, value} map with
 * every field a string, applies caller overrides (occ `--env`, UI deploy options, stored deploy
 * options on update), and drops variables whose final value is empty.
 */
class ExAppEnvVarsHelper {
	/**
	 * @param array $variables raw `environment-variables.variable` entries: a list, or a single entry as produced by SimpleXML for one `<variable>` element
	 * @param array $overrides deploy-option overrides, NAME => value or NAME => ['value' => value]; overrides for undeclared names are ignored
	 * @return array normalized NAME-keyed map, entries with an empty final value removed
	 * @throws InvalidArgumentException on the first malformed variable; message identifies the entry and field
	 */
	public static function normalizeAndValidate(array $variables, array $overrides): array {
		if (!array_is_list($variables)) {
			$variables = [$variables];
		}
		$envVars = [];
		foreach ($variables as $index => $variable) {
			if (!is_array($variable)) {
				throw new InvalidArgumentException(sprintf('variable #%d: entry must be an object, got %s', $index, get_debug_type($variable)));
			}
			$name = $variable['name'] ?? null;
			if (!is_string($name) || trim($name) === '') {
				throw new InvalidArgumentException(sprintf("variable #%d: 'name' must be a non-empty string, got %s", $index, get_debug_type($name)));
			}
			$default = self::toString($variable['default'] ?? '');
			$envVars[$name] = [
				'name' => $name,
				'displayName' => self::toString($variable['display-name'] ?? ''),
				'description' => self::toString($variable['description'] ?? ''),
				'default' => $default,
				'value' => $default,
			];
		}
		foreach ($overrides as $name => $value) {
			if (array_key_exists($name, $envVars)) {
				$envVars[$name]['value'] = self::toString($value['value'] ?? $value ?? '');
			}
		}
		return array_filter($envVars, static function (array $envVar) {
			return $envVar['value'] !== '';
		});
	}

	/**
	 * An empty XML element arrives as [] after the simplexml/json roundtrip: treat any
	 * non-scalar as an empty string so the empty-value filter applies to every input shape.
	 */
	private static function toString(mixed $value): string {
		return is_scalar($value) ? (string)$value : '';
	}
}
