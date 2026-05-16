<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use InvalidArgumentException;

/**
 * Normalize and validate ExApp routes from info.xml / --json-info before they are persisted.
 *
 * Two input shapes feed this helper:
 *   - JSON (`--json-info`): values are already typed (access_level: int, bruteforce_protection: int[], headers_to_exclude: string[]).
 *   - XML (`--info-xml` / appstore): JSON-encoded lists arrive as strings inside element bodies
 *     (`<bruteforce_protection>[401]</bruteforce_protection>`), access_level as `PUBLIC|USER|ADMIN`.
 *
 * The helper produces a canonical structure: access_level as 0/1/2, bruteforce_protection as int[],
 * headers_to_exclude as string[]. Anything that cannot be reconciled to that shape is rejected with a
 * descriptive message — devs see the actual problem instead of the values being silently coerced to `[]`.
 */
class ExAppRouteHelper {
	private const ACCESS_LEVEL_BY_NAME = [
		'PUBLIC' => 0,
		'USER' => 1,
		'ADMIN' => 2,
	];

	/**
	 * @param array $routes raw route entries from getAppInfo's shape-collapse step
	 * @return array normalized routes ready for ExAppMapper::registerExAppRoutes
	 * @throws InvalidArgumentException on the first malformed field; message identifies the route and field
	 */
	public static function normalizeAndValidate(array $routes): array {
		$normalized = [];
		foreach ($routes as $index => $route) {
			if (!is_array($route)) {
				throw new InvalidArgumentException(sprintf('route #%d: entry must be an object, got %s', $index, get_debug_type($route)));
			}
			$normalized[] = self::normalizeRoute($route, $index);
		}
		return $normalized;
	}

	private static function normalizeRoute(array $route, int $index): array {
		$url = $route['url'] ?? null;
		if (!is_string($url) || trim($url) === '') {
			throw new InvalidArgumentException(sprintf("route #%d: 'url' must be a non-empty string, got %s", $index, self::describe($url)));
		}
		$ident = sprintf("route '%s'", $url);

		$verb = $route['verb'] ?? null;
		if (!is_string($verb) || trim($verb) === '') {
			throw new InvalidArgumentException(sprintf("%s: 'verb' must be a non-empty string (e.g. 'GET' or 'GET,POST'), got %s", $ident, self::describe($verb)));
		}

		return [
			'url' => $url,
			'verb' => $verb,
			'access_level' => self::normalizeAccessLevel($route['access_level'] ?? null, $ident),
			'bruteforce_protection' => self::normalizeIntList($route['bruteforce_protection'] ?? null, $ident, 'bruteforce_protection'),
			'headers_to_exclude' => self::normalizeStringList($route['headers_to_exclude'] ?? null, $ident, 'headers_to_exclude'),
		];
	}

	private static function normalizeAccessLevel(mixed $raw, string $ident): int {
		if (is_string($raw)) {
			if (!array_key_exists($raw, self::ACCESS_LEVEL_BY_NAME)) {
				throw new InvalidArgumentException(sprintf("%s: invalid 'access_level' '%s' (allowed: PUBLIC, USER, ADMIN)", $ident, $raw));
			}
			return self::ACCESS_LEVEL_BY_NAME[$raw];
		}
		if (is_int($raw)) {
			if (!in_array($raw, self::ACCESS_LEVEL_BY_NAME, true)) {
				throw new InvalidArgumentException(sprintf("%s: invalid 'access_level' %d (allowed: 0=PUBLIC, 1=USER, 2=ADMIN)", $ident, $raw));
			}
			return $raw;
		}
		throw new InvalidArgumentException(sprintf("%s: 'access_level' is required and must be one of PUBLIC|USER|ADMIN (or 0|1|2), got %s", $ident, self::describe($raw)));
	}

	/**
	 * Accept array<int>, a JSON-encoded array of ints (from XML body), null, or empty string.
	 * Reject anything else.
	 */
	private static function normalizeIntList(mixed $raw, string $ident, string $field): array {
		$list = self::decodeListOrNull($raw, $ident, $field);
		if ($list === null) {
			return [];
		}
		$out = [];
		foreach ($list as $index => $value) {
			if (!is_int($value)) {
				throw new InvalidArgumentException(sprintf("%s: '%s' must contain only integers (e.g. HTTP status codes), entry at index %d is %s", $ident, $field, $index, self::describe($value)));
			}
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * Accept array<string>, a JSON-encoded array of strings (from XML body), null, or empty string.
	 * Reject anything else.
	 */
	private static function normalizeStringList(mixed $raw, string $ident, string $field): array {
		$list = self::decodeListOrNull($raw, $ident, $field);
		if ($list === null) {
			return [];
		}
		$out = [];
		foreach ($list as $index => $value) {
			if (!is_string($value)) {
				throw new InvalidArgumentException(sprintf("%s: '%s' must contain only strings (header names), entry at index %d is %s", $ident, $field, $index, self::describe($value)));
			}
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * Resolve the raw list field to either a PHP list (caller validates element types)
	 * or null (= field is unset / explicitly empty). Throw for anything else, including
	 * associative arrays / JSON objects — those usually indicate the developer authored XML
	 * sub-elements (`<bruteforce_protection><status>401</status></...>`) instead of the
	 * documented JSON-string body (`<bruteforce_protection>[401]</...>`), and dropping the
	 * keys silently would hide that mistake.
	 */
	private static function decodeListOrNull(mixed $raw, string $ident, string $field): ?array {
		if ($raw === null || $raw === '' || $raw === []) {
			return null;
		}
		if (is_array($raw)) {
			if (!array_is_list($raw)) {
				throw new InvalidArgumentException(sprintf("%s: '%s' must be a JSON array (list), got an associative object with keys %s — use a JSON-encoded array body in info.xml (e.g. '[401,429]')", $ident, $field, json_encode(array_keys($raw))));
			}
			return $raw;
		}
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			if (!is_array($decoded) || !array_is_list($decoded)) {
				throw new InvalidArgumentException(sprintf("%s: '%s' must be a JSON array, got string '%s'", $ident, $field, $raw));
			}
			return $decoded;
		}
		throw new InvalidArgumentException(sprintf("%s: '%s' must be an array (or a JSON-encoded array string), got %s", $ident, $field, self::describe($raw)));
	}

	private static function describe(mixed $value): string {
		if (is_string($value)) {
			return sprintf("'%s' (string)", $value);
		}
		return get_debug_type($value);
	}
}
