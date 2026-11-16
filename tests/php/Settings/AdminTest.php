<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Settings;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Settings\Admin;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminTest extends TestCase {
	public function testADockerSocketProxyDefaultDaemonIsNotAccessibleWithoutContactingIt(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'default_daemon_config' ? 'docker_aio' : $default,
		);
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
		$daemonConfigService->method('getDaemonConfigByName')->with('docker_aio')
			->willReturn(new DaemonConfig(['name' => 'docker_aio', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]));
		$dockerActions = $this->createMock(DockerActions::class);
		$dockerActions->expects(self::never())->method('initGuzzleClient');
		$dockerActions->expects(self::never())->method('ping');
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects(self::once())->method('provideInitialState')
			->with('admin-initial-data', self::callback(static fn (array $data): bool => $data['daemon_config_accessible'] === false));

		(new Admin(
			$initialState,
			$daemonConfigService,
			$appConfig,
			$dockerActions,
			$this->createMock(KubernetesActions::class),
			$this->createMock(LoggerInterface::class),
		))->getForm();
	}
}
