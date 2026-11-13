<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Db;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ExAppMapperTest extends TestCase {

	/**
	 * `parseJsonList` is used by the proxy controller and HaRP route serializer to read the
	 * nullable `bruteforce_protection` / `headers_to_exclude` columns. The matrix below covers
	 * every row shape we have seen or can construct: legacy NULLs, malformed JSON, non-string
	 * inputs, and well-formed payloads.
	 */
	#[DataProvider('jsonListProvider')]
	public function testParseJsonList(mixed $raw, array $expected): void {
		self::assertSame($expected, ExAppMapper::parseJsonList($raw));
	}

	public static function jsonListProvider(): array {
		return [
			'null'                     => [null, []],
			'empty string'             => ['', []],
			'empty array string'       => ['[]', []],
			'valid array of ints'      => ['[401,403]', [401, 403]],
			'valid array of strings'   => ['["Cookie","Authorization"]', ['Cookie', 'Authorization']],
			'invalid json'             => ['{broken', []],
			'json literal "null"'      => ['null', []],
			'json scalar string'       => ['"foo"', []],
			'json scalar int'          => ['42', []],
			'non-string input (array)' => [['Cookie'], []],
			'non-string input (int)'   => [42, []],
			'non-string input (bool)'  => [false, []],
		];
	}

	/**
	 * info.xml gives `headers_to_exclude` as a JSON string, `--json-info` as an array.
	 */
	#[Group('DB')]
	#[DataProvider('headersToExcludeProvider')]
	public function testRegisterExAppRoutesStoresHeadersToExclude(string|array $headersToExclude): void {
		$mapper = Server::get(ExAppMapper::class);
		$exApp = new ExApp(['appid' => 'phpunit_route_headers']);
		$mapper->removeExAppRoutes($exApp);
		$mapper->registerExAppRoutes($exApp, [
			['url' => '^/api/.*', 'verb' => 'GET', 'access_level' => 1, 'headers_to_exclude' => $headersToExclude],
		]);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$stored = $qb->select('headers_to_exclude')
			->from('ex_apps_routes')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('phpunit_route_headers')))
			->executeQuery()
			->fetchOne();
		$mapper->removeExAppRoutes($exApp);
		self::assertSame('["Cookie"]', $stored);
	}

	public static function headersToExcludeProvider(): array {
		return [
			'info.xml' => ['["Cookie"]'],
			'--json-info' => [['Cookie']],
		];
	}
}
