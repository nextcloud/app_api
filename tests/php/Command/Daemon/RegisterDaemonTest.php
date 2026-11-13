<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Command\Daemon;

use OCA\AppAPI\Command\Daemon\RegisterDaemon;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RegisterDaemonTest extends TestCase {
	public static function dockerWithoutHarpProvider(): array {
		return [
			'Docker Socket Proxy' => ['nextcloud-appapi-dsp:2375'],
			'Docker socket' => ['/var/run/docker.sock'],
		];
	}

	#[DataProvider('dockerWithoutHarpProvider')]
	public function testRefusesADockerDaemonWithoutHarp(string $host): void {
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->expects(self::never())->method('registerDaemonConfig');
		$commandTester = new CommandTester(new RegisterDaemon($daemonConfigService, $this->createMock(IAppConfig::class)));

		self::assertSame(1, $commandTester->execute([
			'name' => 'docker_local',
			'display-name' => 'Docker',
			'accepts-deploy-id' => 'docker-install',
			'protocol' => 'http',
			'host' => $host,
			'nextcloud_url' => 'http://nextcloud.local',
		]));
		self::assertStringContainsString('Docker Socket Proxy is no longer supported, a `docker-install` daemon requires --harp.', $commandTester->getDisplay());
	}
}
