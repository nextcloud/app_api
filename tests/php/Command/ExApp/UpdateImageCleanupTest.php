<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Command\ExApp;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Command\ExApp\Update;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateImageCleanupTest extends TestCase {
	private DockerActions&MockObject $dockerActions;
	private LoggerInterface&MockObject $logger;
	private IAppConfig&MockObject $appConfig;
	private Update $command;

	protected function setUp(): void {
		parent::setUp();

		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->dockerActions->method('getAcceptsDeployId')
			->willReturn(DockerActions::DEPLOY_ID);

		$this->command = new Update(
			$this->createMock(AppAPIService::class),
			$this->createMock(ExAppService::class),
			$this->createMock(DaemonConfigService::class),
			$this->dockerActions,
			$this->createMock(KubernetesActions::class),
			$this->createMock(ManualActions::class),
			$this->logger,
			$this->createMock(ExAppArchiveFetcher::class),
			$this->createMock(ExAppFetcher::class),
			$this->createMock(ExAppDeployOptionsService::class),
			$this->appConfig,
		);
	}

	public function testRemovesOldImageWhenEnabled(): void {
		$daemon = $this->createDaemonConfig(DockerActions::DEPLOY_ID);

		$this->appConfig->method('getValueBool')
			->willReturnCallback(function (string $appId, string $key, bool $default) {
				if ($key === Application::CONF_IMAGE_CLEANUP_ON_UPDATE) {
					return true;
				}
				return $default;
			});

		$this->dockerActions->expects(self::once())
			->method('buildDockerUrl')
			->with($daemon)
			->willReturn('http://localhost');

		$this->dockerActions->expects(self::once())
			->method('removeImage')
			->with('http://localhost', 'ghcr.io/nextcloud/app:1.0')
			->willReturn('');

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('removed after updating'));

		$this->invokeRemoveOldImage('ghcr.io/nextcloud/app:1.0', $daemon, 'test-app');
	}

	public function testSkipsWhenDisabled(): void {
		$daemon = $this->createDaemonConfig(DockerActions::DEPLOY_ID);

		$this->appConfig->method('getValueBool')
			->willReturnCallback(function (string $appId, string $key, bool $default) {
				if ($key === Application::CONF_IMAGE_CLEANUP_ON_UPDATE) {
					return false;
				}
				return $default;
			});

		$this->dockerActions->expects(self::never())
			->method('removeImage');

		$this->invokeRemoveOldImage('ghcr.io/nextcloud/app:1.0', $daemon, 'test-app');
	}

	public function testSkipsWhenOldImageNameEmpty(): void {
		$daemon = $this->createDaemonConfig(DockerActions::DEPLOY_ID);

		$this->dockerActions->expects(self::never())
			->method('removeImage');

		$this->invokeRemoveOldImage('', $daemon, 'test-app');
	}

	public function testSkipsForNonDockerDaemons(): void {
		$daemon = $this->createDaemonConfig('kubernetes-install');

		$this->appConfig->method('getValueBool')
			->willReturn(true);

		$this->dockerActions->expects(self::never())
			->method('removeImage');

		$this->invokeRemoveOldImage('ghcr.io/nextcloud/app:1.0', $daemon, 'test-app');
	}

	public function testLogsWarningWhenRemoveFails(): void {
		$daemon = $this->createDaemonConfig(DockerActions::DEPLOY_ID);

		$this->appConfig->method('getValueBool')
			->willReturnCallback(function (string $appId, string $key, bool $default) {
				if ($key === Application::CONF_IMAGE_CLEANUP_ON_UPDATE) {
					return true;
				}
				return $default;
			});

		$this->dockerActions->method('buildDockerUrl')->willReturn('http://localhost');
		$this->dockerActions->method('removeImage')
			->willReturn('Failed to remove image: server error');

		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('Old image cleanup for test-app'));

		$this->invokeRemoveOldImage('ghcr.io/nextcloud/app:1.0', $daemon, 'test-app');
	}

	public function testDefaultSettingIsDisabled(): void {
		$daemon = $this->createDaemonConfig(DockerActions::DEPLOY_ID);

		// Don't configure appConfig — let it return the default (false)
		$this->appConfig->method('getValueBool')
			->willReturnCallback(function (string $appId, string $key, bool $default) {
				return $default;
			});

		$this->dockerActions->expects(self::never())
			->method('removeImage');

		$this->invokeRemoveOldImage('ghcr.io/nextcloud/app:1.0', $daemon, 'test-app');
	}

	private function createDaemonConfig(string $deployId): DaemonConfig {
		return new DaemonConfig([
			'name' => 'test-daemon',
			'accepts_deploy_id' => $deployId,
			'deploy_config' => [],
		]);
	}

	private function invokeRemoveOldImage(string $oldImageName, DaemonConfig $daemonConfig, string $appId): void {
		$method = new \ReflectionMethod($this->command, 'removeOldImageIfEnabled');
		$method->invoke($this->command, $oldImageName, $daemonConfig, $appId);
	}
}
