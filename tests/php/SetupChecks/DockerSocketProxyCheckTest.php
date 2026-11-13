<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\SetupChecks;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\SetupChecks\DockerSocketProxyCheck;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

class DockerSocketProxyCheckTest extends TestCase {
	private function runCheck(array $daemonConfigs): SetupResult {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getRegisteredDaemonConfigs')->willReturn($daemonConfigs);

		return (new DockerSocketProxyCheck($l10n, $daemonConfigService))->run();
	}

	public function testNamesTheDockerSocketProxyDaemons(): void {
		$result = $this->runCheck([
			new DaemonConfig(['name' => 'docker_local', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]),
			new DaemonConfig(['name' => 'harp_proxy', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => ['harp' => ['frp_address' => 'appapi-harp:8782']]]),
			new DaemonConfig(['name' => 'docker_aio', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]),
			new DaemonConfig(['name' => 'manual_install', 'accepts_deploy_id' => 'manual-install', 'deploy_config' => []]),
		]);

		self::assertSame(SetupResult::ERROR, $result->getSeverity());
		self::assertSame('Docker Socket Proxy daemons are no longer supported: docker_local, docker_aio. Uninstall their ExApps keeping the data, install them on a HaRP daemon on the same Docker host, then remove these daemons.', $result->getDescription());
	}

	public function testDaemonsWithHarpAreNotReported(): void {
		$result = $this->runCheck([
			new DaemonConfig(['name' => 'harp_proxy', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => ['harp' => ['frp_address' => 'appapi-harp:8782']]]),
			new DaemonConfig(['name' => 'manual_install', 'accepts_deploy_id' => 'manual-install', 'deploy_config' => []]),
		]);

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}
}
