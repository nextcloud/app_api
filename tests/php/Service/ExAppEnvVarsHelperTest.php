<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use InvalidArgumentException;
use OCA\AppAPI\Service\ExAppEnvVarsHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExAppEnvVarsHelperTest extends TestCase {

	/**
	 * Regression test for https://github.com/nextcloud/app_api/issues/969: an empty <default>
	 * element must not survive as an empty array and end up as the literal string `Array` in
	 * the container environment. The input is produced by the same simplexml/json roundtrip
	 * getAppInfo uses, so the [] shape is real, not hand-crafted.
	 */
	#[DataProvider('emptyDefaultXmlProvider')]
	public function testEmptyDefaultElementFromRealXmlIsDropped(string $xml): void {
		$parsed = json_decode(json_encode((array)simplexml_load_string($xml)), true);
		$variables = $parsed['environment-variables']['variable'];

		// lock in the SimpleXML behavior the bug depends on: empty element parses to []
		self::assertSame([], $variables['default']);

		self::assertSame([], ExAppEnvVarsHelper::normalizeAndValidate($variables, []));
	}

	public static function emptyDefaultXmlProvider(): array {
		return [
			'<default></default>' => [
				'<external-app><environment-variables><variable>'
				. '<name>EMPTY_ELEM</name><display-name>Empty</display-name><description>d</description><default></default>'
				. '</variable></environment-variables></external-app>',
			],
			'<default/>' => [
				'<external-app><environment-variables><variable>'
				. '<name>EMPTY_ELEM</name><display-name>Empty</display-name><description>d</description><default/>'
				. '</variable></environment-variables></external-app>',
			],
		];
	}

	#[DataProvider('validVariablesProvider')]
	public function testNormalizeAndValidate(array $variables, array $overrides, array $expected): void {
		self::assertSame($expected, ExAppEnvVarsHelper::normalizeAndValidate($variables, $overrides));
	}

	public static function validVariablesProvider(): array {
		return [
			'single <variable> arrives as one object, not a list' => [
				['name' => 'A', 'display-name' => 'Var A', 'description' => 'desc', 'default' => 'x'],
				[],
				['A' => ['name' => 'A', 'displayName' => 'Var A', 'description' => 'desc', 'default' => 'x', 'value' => 'x']],
			],
			'variable without <default> is dropped' => [
				[['name' => 'A', 'display-name' => 'Var A', 'description' => 'desc']],
				[],
				[],
			],
			'empty-element default ([]) is dropped, sibling with a value survives' => [
				[
					['name' => 'EMPTY_ELEM', 'display-name' => 'Empty', 'description' => 'd', 'default' => []],
					['name' => 'KEPT', 'display-name' => 'Kept', 'description' => 'd', 'default' => 'v'],
				],
				[],
				['KEPT' => ['name' => 'KEPT', 'displayName' => 'Kept', 'description' => 'd', 'default' => 'v', 'value' => 'v']],
			],
			'empty-element display-name and description become empty strings' => [
				[['name' => 'A', 'display-name' => [], 'description' => [], 'default' => 'x']],
				[],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => 'x']],
			],
			'override replaces the default value' => [
				[['name' => 'A', 'default' => 'x']],
				['A' => 'y'],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => 'y']],
			],
			'override with empty value drops the variable' => [
				[['name' => 'A', 'default' => 'x']],
				['A' => ''],
				[],
			],
			'override in stored deploy-options shape' => [
				[['name' => 'A', 'default' => 'x']],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => 'y']],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => 'y']],
			],
			'stored pre-fix deploy option with [] value is dropped, not deployed as Array' => [
				[['name' => 'A', 'default' => []]],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => [], 'value' => []]],
				[],
			],
			'override for an undeclared variable is ignored' => [
				[['name' => 'A', 'default' => 'x']],
				['B' => 'y'],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => 'x']],
			],
			'no variables declared' => [[], ['A' => 'y'], []],
			'numeric override is canonicalized to string' => [
				[['name' => 'A', 'default' => 'x']],
				['A' => 123],
				['A' => ['name' => 'A', 'displayName' => '', 'description' => '', 'default' => 'x', 'value' => '123']],
			],
		];
	}

	#[DataProvider('invalidVariablesProvider')]
	public function testNormalizeAndValidateRejects(array $variables, string $expectedMessageFragment): void {
		try {
			ExAppEnvVarsHelper::normalizeAndValidate($variables, []);
			self::fail('Expected InvalidArgumentException, none thrown');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString($expectedMessageFragment, $e->getMessage());
		}
	}

	public static function invalidVariablesProvider(): array {
		return [
			'entry is not an array' => [
				['not-an-object'],
				'variable #0: entry must be an object',
			],
			'missing name' => [
				[['display-name' => 'X', 'default' => 'v']],
				"variable #0: 'name' must be a non-empty string",
			],
			'empty <name/> element parses to an array' => [
				[['name' => [], 'default' => 'v']],
				"variable #0: 'name' must be a non-empty string",
			],
			'whitespace-only name' => [
				[['name' => '  ', 'default' => 'v']],
				"variable #0: 'name' must be a non-empty string",
			],
		];
	}
}
