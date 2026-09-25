<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\DeployActions;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

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

	public static function imageNameProvider(): array {
		return [
			'no registry mappings' => [[], 'ghcr.io'],
			'mapped registry' => [[['from' => 'ghcr.io', 'to' => 'registry.example.com/']], 'registry.example.com'],
			'mapping of another registry' => [[['from' => 'docker.io', 'to' => 'registry.example.com']], 'ghcr.io'],
			'local keeps the image name' => [[['from' => 'ghcr.io', 'to' => 'local']], 'ghcr.io'],
		];
	}

	#[DataProvider('imageNameProvider')]
	public function testBuildBaseImageName(array $registries, string $expectedRegistry): void {
		$imageParams = ['image_src' => 'ghcr.io', 'image_name' => 'nextcloud/test-deploy', 'image_tag' => 'release'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => $registries]]);

		self::assertSame(
			$expectedRegistry . '/nextcloud/test-deploy:release',
			$this->dockerActions->buildBaseImageName($imageParams, $daemonConfig),
		);
	}

	#[DataProvider('imageNameProvider')]
	public function testBuildExtendedImageName(array $registries, string $expectedRegistry): void {
		$imageParams = ['image_src' => 'ghcr.io', 'image_name' => 'nextcloud/test-deploy', 'image_tag' => 'release'];
		$daemonConfig = new DaemonConfig([
			'deploy_config' => ['registries' => $registries, 'computeDevice' => ['id' => 'cuda']],
		]);

		$buildExtendedImageName = new ReflectionMethod($this->dockerActions, 'buildExtendedImageName');
		self::assertSame(
			$expectedRegistry . '/nextcloud/test-deploy:release-cuda',
			$buildExtendedImageName->invoke($this->dockerActions, $imageParams, $daemonConfig),
		);
	}

	public function testBuildExtendedImageNameWithoutComputeDevice(): void {
		$imageParams = ['image_src' => 'ghcr.io', 'image_name' => 'nextcloud/test-deploy', 'image_tag' => 'release'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => []]]);

		$buildExtendedImageName = new ReflectionMethod($this->dockerActions, 'buildExtendedImageName');
		self::assertNull($buildExtendedImageName->invoke($this->dockerActions, $imageParams, $daemonConfig));
	}

	public static function shouldPullImageProvider(): array {
		$local = ['from' => 'ghcr.io', 'to' => 'local'];
		return [
			'no registry mappings' => [[], true],
			'mapped to a mirror' => [[['from' => 'ghcr.io', 'to' => 'registry.example.com']], true],
			'mapped to local' => [[$local], false],
			'local mapping of another registry' => [[['from' => 'docker.io', 'to' => 'local']], true],
			'malformed entries are ignored' => [['ghcr.io', ['from' => 'ghcr.io'], ['to' => 'local'], $local], false],
			'legacy duplicate source: the first entry wins' => [
				[$local, ['from' => 'ghcr.io', 'to' => 'registry.example.com']],
				false,
			],
			'local with a trailing slash' => [[['from' => 'ghcr.io', 'to' => 'local/']], false],
			'legacy duplicate source, mirror first: the mirror is pulled' => [
				[['from' => 'ghcr.io', 'to' => 'registry.example.com'], $local],
				true,
			],
		];
	}

	#[DataProvider('shouldPullImageProvider')]
	public function testShouldPullImage(array $registries, bool $expected): void {
		$imageParams = ['image_src' => 'ghcr.io', 'image_name' => 'nextcloud/test-deploy', 'image_tag' => 'release'];
		$daemonConfig = new DaemonConfig(['deploy_config' => ['registries' => $registries]]);

		$shouldPullImage = new ReflectionMethod($this->dockerActions, 'shouldPullImage');
		self::assertSame($expected, $shouldPullImage->invoke($this->dockerActions, $imageParams, $daemonConfig));
	}
}
