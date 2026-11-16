<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\SetupChecks;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\SetupChecks\DaemonCheck;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DaemonCheckTest extends TestCase {
	public function testADockerSocketProxyDefaultDaemonIsAnErrorWithoutContactingIt(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('docker_aio');
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getDaemonConfigByName')->with('docker_aio')
			->willReturn(new DaemonConfig(['name' => 'docker_aio', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]));
		$dockerActions = $this->createMock(DockerActions::class);
		$dockerActions->expects(self::never())->method('initGuzzleClient');
		$dockerActions->expects(self::never())->method('ping');

		$result = (new DaemonCheck(
			$l10n,
			$this->createMock(IConfig::class),
			$appConfig,
			$dockerActions,
			$this->createMock(KubernetesActions::class),
			$this->createMock(LoggerInterface::class),
			$daemonConfigService,
		))->run();

		self::assertSame(SetupResult::ERROR, $result->getSeverity());
		self::assertSame('The default deploy daemon uses Docker Socket Proxy, which is no longer supported. Set a HaRP daemon as the default.', $result->getDescription());
	}
}
