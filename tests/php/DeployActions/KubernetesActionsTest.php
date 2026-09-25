<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\DeployActions;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\AppAPICommonService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCP\App\IAppManager;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

class KubernetesActionsTest extends TestCase {
	private const IMAGE_PARAMS = [
		'image_src' => 'ghcr.io',
		'image_name' => 'nextcloud/test-deploy',
		'image_tag' => 'release',
	];

	private KubernetesActions $kubernetesActions;

	protected function setUp(): void {
		parent::setUp();

		$this->kubernetesActions = new KubernetesActions(
			$this->createMock(LoggerInterface::class),
			$this->createMock(IConfig::class),
			$this->createMock(ICertificateManager::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(AppAPICommonService::class),
			$this->createMock(ExAppService::class),
			$this->createMock(ICrypto::class),
			$this->createMock(ExAppDeployOptionsService::class),
		);
	}

	public static function buildImageNameProvider(): array {
		return [
			'no registry mappings' => [[], 'ghcr.io/nextcloud/test-deploy:release'],
			'mapped registry' => [
				[['from' => 'ghcr.io', 'to' => 'registry.example.com']],
				'registry.example.com/nextcloud/test-deploy:release',
			],
			'mapping of another registry' => [
				[['from' => 'docker.io', 'to' => 'registry.example.com']],
				'ghcr.io/nextcloud/test-deploy:release',
			],
			'local keeps the image name' => [
				[['from' => 'ghcr.io', 'to' => 'local']],
				'ghcr.io/nextcloud/test-deploy:release',
			],
		];
	}

	#[DataProvider('buildImageNameProvider')]
	public function testBuildImageName(array $registries, string $expected): void {
		$daemonConfig = new DaemonConfig([
			'accepts_deploy_id' => KubernetesActions::DEPLOY_ID,
			'deploy_config' => ['registries' => $registries],
		]);

		self::assertSame($expected, $this->kubernetesActions->buildImageName(self::IMAGE_PARAMS, $daemonConfig));
	}

	public function testBuildImageNameWithoutRegistriesInDeployConfig(): void {
		$daemonConfig = new DaemonConfig(['deploy_config' => ['kubernetes' => ['expose_type' => 'clusterip']]]);

		self::assertSame(
			'ghcr.io/nextcloud/test-deploy:release',
			$this->kubernetesActions->buildImageName(self::IMAGE_PARAMS, $daemonConfig),
		);
	}

	public function testDeployExAppSendsTheMappedImageToHarp(): void {
		$createPayloads = $this->deployWithMappedRegistry([]);

		self::assertCount(1, $createPayloads);
		self::assertSame('registry.example.com/nextcloud/test-deploy:release', $createPayloads[0]['image']);
		self::assertArrayNotHasKey('role_suffix', $createPayloads[0]);
	}

	public function testDeployExAppNeverPullsForALocalMapping(): void {
		$createPayloads = $this->deployWithMappedRegistry([], [['from' => 'ghcr.io', 'to' => 'local']]);

		self::assertSame('ghcr.io/nextcloud/test-deploy:release', $createPayloads[0]['image']);
		self::assertSame('Never', $createPayloads[0]['image_pull_policy']);
	}

	public function testDeployExAppLeavesThePullPolicyToHarpWithoutALocalMapping(): void {
		foreach ([[], [['from' => 'ghcr.io', 'to' => 'registry.example.com']]] as $registries) {
			$createPayloads = $this->deployWithMappedRegistry([], $registries);

			self::assertArrayNotHasKey('image_pull_policy', $createPayloads[0]);
		}
	}

	public function testDeployExAppSendsTheMappedImageForEveryRole(): void {
		$createPayloads = $this->deployWithMappedRegistry([
			['name' => 'web', 'env' => 'ROLE=web', 'expose' => true],
			['name' => 'worker', 'env' => 'ROLE=worker', 'expose' => false],
		]);

		self::assertSame(['web', 'worker'], array_column($createPayloads, 'role_suffix'));
		foreach ($createPayloads as $payload) {
			self::assertSame('registry.example.com/nextcloud/test-deploy:release', $payload['image']);
		}
	}

	/**
	 * Runs deployExApp() against queued HaRP responses and returns the payloads of the /exapp/create requests.
	 */
	private function deployWithMappedRegistry(array $roles, ?array $registries = null): array {
		$deployments = max(1, count($roles));
		$responses = [new Response(200, [], json_encode(['kubernetes' => ['enabled' => true, 'reachable' => true]]))];
		for ($i = 0; $i < $deployments; $i++) {
			$responses[] = new Response(200, [], json_encode(['exists' => false]));
		}
		for ($i = 0; $i < $deployments; $i++) {
			$responses[] = new Response(201, [], json_encode(['name' => 'nc-app-test-deploy']));
			$responses[] = new Response(204);
			$responses[] = new Response(204);
		}
		for ($i = 0; $i < $deployments; $i++) {
			$responses[] = new Response(200, [], json_encode(['started' => true]));
		}

		$requests = [];
		$mockHandler = new MockHandler($responses);
		$handlerStack = HandlerStack::create($mockHandler);
		$handlerStack->push(Middleware::history($requests));

		$kubernetesActions = $this->getMockBuilder(KubernetesActions::class)
			->setConstructorArgs([
				$this->createMock(LoggerInterface::class),
				$this->createMock(IConfig::class),
				$this->createMock(ICertificateManager::class),
				$this->createMock(IAppManager::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(AppAPICommonService::class),
				$this->createMock(ExAppService::class),
				$this->createMock(ICrypto::class),
				$this->createMock(ExAppDeployOptionsService::class),
			])
			->onlyMethods(['initGuzzleClient'])
			->getMock();
		(new ReflectionProperty(KubernetesActions::class, 'guzzleClient'))
			->setValue($kubernetesActions, new Client(['handler' => $handlerStack]));

		$daemonConfig = new DaemonConfig([
			'accepts_deploy_id' => KubernetesActions::DEPLOY_ID,
			'protocol' => 'http',
			'host' => 'harp:8780',
			'deploy_config' => ['registries' => $registries ?? [['from' => 'ghcr.io', 'to' => 'registry.example.com']]],
		]);
		$params = [
			'image_params' => self::IMAGE_PARAMS,
			'container_params' => ['name' => 'test-deploy', 'env' => ['APP_ID=test-deploy']],
		];
		if ($roles !== []) {
			$params['k8s_service_roles'] = $roles;
		}

		self::assertSame('', $kubernetesActions->deployExApp(new ExApp(['appid' => 'test-deploy']), $daemonConfig, $params));
		self::assertSame(0, $mockHandler->count());

		$createPayloads = [];
		foreach ($requests as $transaction) {
			if (str_ends_with($transaction['request']->getUri()->getPath(), '/exapp/create')) {
				$createPayloads[] = json_decode((string)$transaction['request']->getBody(), true);
			}
		}
		return $createPayloads;
	}
}
