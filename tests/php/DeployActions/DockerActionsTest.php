<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\DeployActions;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\AppAPICommonService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class DockerActionsTest extends TestCase {
	private DockerActions $dockerActions;
	private IAppConfig&MockObject $appConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->dockerActions = new DockerActions(
			$this->createMock(LoggerInterface::class),
			$this->appConfig,
			$this->createMock(IConfig::class),
			$this->createMock(ICertificateManager::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(AppAPICommonService::class),
			$this->createMock(ExAppService::class),
			$this->createMock(ITempManager::class),
			$this->createMock(ICrypto::class),
			$this->createMock(ExAppDeployOptionsService::class),
		);
	}

	public function testGetDockerApiVersionReturnsDefaultWhenNoConfigSet(): void {
		$this->appConfig->expects(self::once())
			->method('getValueString')
			->with(Application::APP_ID, 'docker_api_version', '', true)
			->willReturn('');

		self::assertSame(DockerActions::DOCKER_API_VERSION, $this->dockerActions->getDockerApiVersion());
	}

	public function testGetDockerApiVersionReturnsCustomVersionFromConfig(): void {
		$this->appConfig->expects(self::once())
			->method('getValueString')
			->with(Application::APP_ID, 'docker_api_version', '', true)
			->willReturn('v1.43');

		self::assertSame('v1.43', $this->dockerActions->getDockerApiVersion());
	}

	public function testBuildApiUrlUsesDefaultVersion(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'docker_api_version', '', true)
			->willReturn('');

		$url = $this->dockerActions->buildApiUrl('http://localhost', '_ping');

		self::assertSame('http://localhost/' . DockerActions::DOCKER_API_VERSION . '/_ping', $url);
	}

	public function testBuildApiUrlUsesCustomVersion(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'docker_api_version', '', true)
			->willReturn('v1.43');

		$url = $this->dockerActions->buildApiUrl('http://localhost', '_ping');

		self::assertSame('http://localhost/v1.43/_ping', $url);
	}

	public function testBuildApiUrlWithContainerRoute(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'docker_api_version', '', true)
			->willReturn('v1.41');

		$url = $this->dockerActions->buildApiUrl(
			'http://localhost:8780',
			sprintf('containers/%s/json', 'nc_app_test')
		);

		self::assertSame('http://localhost:8780/v1.41/containers/nc_app_test/json', $url);
	}
}
