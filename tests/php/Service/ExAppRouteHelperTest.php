<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use InvalidArgumentException;
use OCA\AppAPI\Service\ExAppRouteHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExAppRouteHelperTest extends TestCase {

	/**
	 * Routes that pass validation come back canonicalised: access_level as int 0/1/2,
	 * bruteforce_protection as int[], headers_to_exclude as string[] — regardless of whether
	 * the input arrived from `--json-info` (already typed) or from info.xml (JSON-encoded
	 * strings inside element bodies).
	 */
	#[DataProvider('validRouteProvider')]
	public function testNormalizeAndValidateAcceptsValidShapes(array $input, array $expected): void {
		self::assertSame($expected, ExAppRouteHelper::normalizeAndValidate($input));
	}

	public static function validRouteProvider(): array {
		return [
			'JSON shape — typed values' => [
				[[
					'url' => '/api/.*',
					'verb' => 'GET,POST',
					'access_level' => 1,
					'bruteforce_protection' => [401, 403],
					'headers_to_exclude' => ['Cookie', 'Authorization'],
				]],
				[[
					'url' => '/api/.*',
					'verb' => 'GET,POST',
					'access_level' => 1,
					'bruteforce_protection' => [401, 403],
					'headers_to_exclude' => ['Cookie', 'Authorization'],
				]],
			],
			'XML shape — access_level string + JSON-encoded list bodies' => [
				[[
					'url' => '/.*',
					'verb' => 'GET',
					'access_level' => 'USER',
					'bruteforce_protection' => '[401,429]',
					'headers_to_exclude' => '["Cookie"]',
				]],
				[[
					'url' => '/.*',
					'verb' => 'GET',
					'access_level' => 1,
					'bruteforce_protection' => [401, 429],
					'headers_to_exclude' => ['Cookie'],
				]],
			],
			'XML shape — empty element bodies (SimpleXML produces empty arrays)' => [
				// <headers_to_exclude></headers_to_exclude> arrives as [] after simplexml→json roundtrip
				[[
					'url' => '/.*',
					'verb' => 'POST',
					'access_level' => 'PUBLIC',
					'bruteforce_protection' => [],
					'headers_to_exclude' => [],
				]],
				[[
					'url' => '/.*',
					'verb' => 'POST',
					'access_level' => 0,
					'bruteforce_protection' => [],
					'headers_to_exclude' => [],
				]],
			],
			'JSON path — developer sends empty strings for list fields' => [
				// Defensive: --json-info '{"routes":[{"headers_to_exclude":""}]}'
				[[
					'url' => '/.*',
					'verb' => 'POST',
					'access_level' => 'PUBLIC',
					'bruteforce_protection' => '',
					'headers_to_exclude' => '',
				]],
				[[
					'url' => '/.*',
					'verb' => 'POST',
					'access_level' => 0,
					'bruteforce_protection' => [],
					'headers_to_exclude' => [],
				]],
			],
			'omitted optional fields default to empty' => [
				[[
					'url' => '/.*',
					'verb' => 'GET',
					'access_level' => 'ADMIN',
				]],
				[[
					'url' => '/.*',
					'verb' => 'GET',
					'access_level' => 2,
					'bruteforce_protection' => [],
					'headers_to_exclude' => [],
				]],
			],
			'multiple routes preserve order' => [
				[
					['url' => '/a', 'verb' => 'GET', 'access_level' => 'USER'],
					['url' => '/b', 'verb' => 'POST', 'access_level' => 'ADMIN'],
				],
				[
					['url' => '/a', 'verb' => 'GET', 'access_level' => 1, 'bruteforce_protection' => [], 'headers_to_exclude' => []],
					['url' => '/b', 'verb' => 'POST', 'access_level' => 2, 'bruteforce_protection' => [], 'headers_to_exclude' => []],
				],
			],
			'empty input list' => [[], []],
		];
	}

	/**
	 * Every rejection case names the offending field in the message — that is the whole
	 * point of fail-fast registration: developers must be able to read the OCC output and
	 * know exactly which route entry to fix in their info.xml or --json-info payload.
	 */
	#[DataProvider('invalidRouteProvider')]
	public function testNormalizeAndValidateRejects(array $input, string $expectedMessageFragment): void {
		try {
			ExAppRouteHelper::normalizeAndValidate($input);
			self::fail('Expected InvalidArgumentException, none thrown');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString($expectedMessageFragment, $e->getMessage());
		}
	}

	public static function invalidRouteProvider(): array {
		return [
			'entry is not an array' => [
				['not-an-object'],
				'route #0: entry must be an object',
			],
			'missing url' => [
				[['verb' => 'GET', 'access_level' => 'USER']],
				"route #0: 'url' must be a non-empty string",
			],
			'empty url' => [
				[['url' => '', 'verb' => 'GET', 'access_level' => 'USER']],
				"'url' must be a non-empty string",
			],
			'missing verb' => [
				[['url' => '/.*', 'access_level' => 'USER']],
				"'verb' must be a non-empty string",
			],
			'verb is array' => [
				[['url' => '/.*', 'verb' => ['GET', 'POST'], 'access_level' => 'USER']],
				"'verb' must be a non-empty string",
			],
			'missing access_level' => [
				[['url' => '/.*', 'verb' => 'GET']],
				"'access_level' is required",
			],
			'unknown access_level string' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'SUPERUSER']],
				"invalid 'access_level' 'SUPERUSER'",
			],
			'out-of-range access_level int' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 99]],
				"invalid 'access_level' 99",
			],
			'bruteforce_protection contains a string' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'bruteforce_protection' => [401, 'oops']]],
				"'bruteforce_protection' must contain only integers",
			],
			'bruteforce_protection is JSON of strings' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'bruteforce_protection' => '["401"]']],
				"'bruteforce_protection' must contain only integers",
			],
			'bruteforce_protection is malformed JSON' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'bruteforce_protection' => '{not-json']],
				"'bruteforce_protection' must be a JSON array",
			],
			'bruteforce_protection is scalar int' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'bruteforce_protection' => 401]],
				"'bruteforce_protection' must be an array",
			],
			'headers_to_exclude contains an int' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'headers_to_exclude' => ['Cookie', 42]]],
				"'headers_to_exclude' must contain only strings",
			],
			'headers_to_exclude is JSON of ints' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'headers_to_exclude' => '[1,2]']],
				"'headers_to_exclude' must contain only strings",
			],
			'second route is the broken one' => [
				[
					['url' => '/ok', 'verb' => 'GET', 'access_level' => 'USER'],
					['url' => '/bad', 'verb' => 'GET', 'access_level' => 'WRONG'],
				],
				"route '/bad': invalid 'access_level' 'WRONG'",
			],
			'bruteforce_protection is JSON object' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'bruteforce_protection' => '{"x":401}']],
				"'bruteforce_protection' must be a JSON array",
			],
			'bruteforce_protection is associative array (XML sub-element shape)' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'bruteforce_protection' => ['status-code' => 401]]],
				"'bruteforce_protection' must be a JSON array (list)",
			],
			'headers_to_exclude is associative array' => [
				[['url' => '/.*', 'verb' => 'GET', 'access_level' => 'USER', 'headers_to_exclude' => ['header' => 'Cookie']]],
				"'headers_to_exclude' must be a JSON array (list)",
			],
		];
	}
}
