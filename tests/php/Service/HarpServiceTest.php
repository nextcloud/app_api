<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\HarpService;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HarpServiceTest extends TestCase {

	public function testGetHarpExAppSendsTheRouteVerbs(): void {
		$harpService = new HarpService(
			$this->createMock(LoggerInterface::class),
			$this->createMock(IConfig::class),
			$this->createMock(ICertificateManager::class),
			$this->createMock(ICrypto::class),
		);
		// One URL with a public GET and an admin-only POST: HaRP needs the verbs to tell the two routes apart.
		$exApp = new ExApp([
			'appid' => 'test-deploy',
			'deploy_config' => [],
			'routes' => [
				['url' => '^/settings$', 'verb' => 'GET', 'access_level' => 0, 'headers_to_exclude' => '[]', 'bruteforce_protection' => '[]'],
				['url' => '^/settings$', 'verb' => 'POST', 'access_level' => 2, 'headers_to_exclude' => '[]', 'bruteforce_protection' => '[401]'],
			],
		]);

		self::assertSame([
			['url' => '^/settings$', 'verb' => 'GET', 'access_level' => 0, 'bruteforce_protection' => []],
			['url' => '^/settings$', 'verb' => 'POST', 'access_level' => 2, 'bruteforce_protection' => [401]],
		], $harpService->getHarpExApp($exApp)['routes']);
	}
}
