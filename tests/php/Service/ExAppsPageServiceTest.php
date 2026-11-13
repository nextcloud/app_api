<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppsPageService;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExAppsPageServiceTest extends TestCase {
	public function testADockerSocketProxyDefaultDaemonIsNotAccessibleWithoutContactingIt(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('app_api')->willReturn(true);
		$exAppFetcher = $this->createMock(ExAppFetcher::class);
		$exAppFetcher->method('getExAppsWithUpdates')->willReturn([]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('docker_aio');
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getDaemonConfigByName')->with('docker_aio')
			->willReturn(new DaemonConfig(['name' => 'docker_aio', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]));
		$dockerActions = $this->createMock(DockerActions::class);
		$dockerActions->expects(self::never())->method('initGuzzleClient');
		$dockerActions->expects(self::never())->method('ping');
		$states = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(static function (string $key, mixed $value) use (&$states): void {
			$states[$key] = $value;
		});

		(new ExAppsPageService(
			$exAppFetcher,
			$daemonConfigService,
			$dockerActions,
			$this->createMock(KubernetesActions::class),
			$appConfig,
			$appManager,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class),
		))->provideAppApiState($initialState);

		self::assertFalse($states['defaultDaemonConfigAccessible']);
	}
}
