<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Command\ExApp;

use OCA\AppAPI\Command\ExApp\Register;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RegisterTest extends TestCase {
	public function testRefusesADockerSocketProxyDaemonBeforeRegistering(): void {
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getDaemonConfigByName')->with('dsp_daemon')
			->willReturn(new DaemonConfig(['name' => 'dsp_daemon', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]));
		$exAppService = $this->createMock(ExAppService::class);
		$exAppService->method('getAppInfo')->willReturn(['id' => 'test_app']);
		$exAppService->expects(self::never())->method('registerExApp');
		$dockerActions = $this->createMock(DockerActions::class);
		$dockerActions->expects(self::never())->method('deployExApp');

		$commandTester = new CommandTester(new Register(
			$this->createMock(AppAPIService::class),
			$daemonConfigService,
			$dockerActions,
			$this->createMock(ManualActions::class),
			$this->createMock(KubernetesActions::class),
			$this->createMock(IAppConfig::class),
			$exAppService,
			$this->createMock(ISecureRandom::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ExAppArchiveFetcher::class),
		));

		self::assertSame(2, $commandTester->execute(['appid' => 'test_app', 'daemon-config-name' => 'dsp_daemon']));
		self::assertStringContainsString('Daemon config dsp_daemon: ' . DockerActions::DOCKER_SOCKET_PROXY_ERROR, $commandTester->getDisplay());
	}
}
