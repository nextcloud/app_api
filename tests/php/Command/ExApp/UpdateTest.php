<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Command\ExApp;

use OCA\AppAPI\Command\ExApp\Update;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateTest extends TestCase {
	public function testRefusesAnExAppOnADockerSocketProxyDaemon(): void {
		$exAppService = $this->createMock(ExAppService::class);
		$exAppService->method('getAppInfo')->willReturn(['id' => 'test_app', 'version' => '2.0.0']);
		$exAppService->method('getExApp')->with('test_app')
			->willReturn(new ExApp(['appid' => 'test_app', 'version' => '1.0.0', 'daemon_config_name' => 'dsp_daemon', 'enabled' => 1]));
		$exAppService->expects(self::never())->method('updateExApp');
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getDaemonConfigByName')->with('dsp_daemon')
			->willReturn(new DaemonConfig(['name' => 'dsp_daemon', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]));
		$service = $this->createMock(AppAPIService::class);
		$service->expects(self::never())->method('disableExApp');
		$dockerActions = $this->createMock(DockerActions::class);
		$dockerActions->expects(self::never())->method('deployExApp');

		$commandTester = new CommandTester(new Update(
			$service,
			$exAppService,
			$daemonConfigService,
			$dockerActions,
			$this->createMock(KubernetesActions::class),
			$this->createMock(ManualActions::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ExAppArchiveFetcher::class),
			$this->createMock(ExAppFetcher::class),
			$this->createMock(ExAppDeployOptionsService::class),
		));

		self::assertSame(2, $commandTester->execute(['appid' => 'test_app']));
		self::assertStringContainsString('Daemon config dsp_daemon: ' . DockerActions::DOCKER_SOCKET_PROXY_EXAPP_ERROR, $commandTester->getDisplay());
	}
}
