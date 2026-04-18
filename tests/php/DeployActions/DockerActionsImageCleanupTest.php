<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\DeployActions;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DockerActionsImageCleanupTest extends TestCase {
	private DockerActions $dockerActions;
	private IAppConfig&MockObject $appConfig;
	private Client&MockObject $guzzleClient;
	private LoggerInterface&MockObject $logger;

	private const DOCKER_URL = 'http://localhost';
	private const API_VERSION = 'v1.44';

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guzzleClient = $this->createMock(Client::class);

		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $appId, string $key, string $default) {
				if ($key === 'docker_api_version') {
					return '';
				}
				return $default;
			});

		$this->dockerActions = new DockerActions(
			$this->logger,
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

		// Inject mock Guzzle client via reflection (private property)
		$reflection = new \ReflectionClass($this->dockerActions);
		$property = $reflection->getProperty('guzzleClient');
		$property->setValue($this->dockerActions, $this->guzzleClient);
	}

	// --- removeImage() tests ---

	public function testRemoveImageReturnsEmptyStringOnSuccess(): void {
		$this->guzzleClient->expects(self::once())
			->method('delete')
			->with(self::DOCKER_URL . '/' . self::API_VERSION . '/images/test-image:1.0')
			->willReturn(new Response(200));

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('Successfully removed Docker image'));

		$result = $this->dockerActions->removeImage(self::DOCKER_URL, 'test-image:1.0');

		self::assertSame('', $result);
	}

	public function testRemoveImageReturnsEmptyStringOn404(): void {
		$this->guzzleClient->method('delete')->willThrowException(
			new ClientException('Not Found', new Request('DELETE', ''), new Response(404))
		);

		$this->logger->expects(self::once())
			->method('debug')
			->with(self::stringContains('not found (already removed)'));

		$result = $this->dockerActions->removeImage(self::DOCKER_URL, 'missing-image:1.0');

		self::assertSame('', $result);
	}

	public function testRemoveImageReturnsEmptyStringOn409(): void {
		$this->guzzleClient->method('delete')->willThrowException(
			new ClientException('Conflict', new Request('DELETE', ''), new Response(409))
		);

		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains('in use, skipping removal'));

		$result = $this->dockerActions->removeImage(self::DOCKER_URL, 'in-use-image:1.0');

		self::assertSame('', $result);
	}

	public function testRemoveImageReturnsErrorOnServerError(): void {
		$this->guzzleClient->method('delete')->willThrowException(
			new ClientException('Server Error', new Request('DELETE', ''), new Response(500))
		);

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('Failed to remove image'));

		$result = $this->dockerActions->removeImage(self::DOCKER_URL, 'some-image:1.0');

		self::assertNotEmpty($result);
		self::assertStringContainsString('Failed to remove image', $result);
	}

	public function testRemoveImageReturnsErrorOnUnexpectedStatusCode(): void {
		$this->guzzleClient->method('delete')->willReturn(new Response(204));

		$result = $this->dockerActions->removeImage(self::DOCKER_URL, 'test-image:1.0');

		self::assertNotEmpty($result);
		self::assertStringContainsString('Unexpected status 204', $result);
	}

	// --- pruneImages() tests ---

	public function testPruneImagesReturnsResultWithFilters(): void {
		$body = json_encode([
			'SpaceReclaimed' => 104857600,
			'ImagesDeleted' => [
				['Untagged' => 'old-image:1.0'],
				['Deleted' => 'sha256:abc123'],
			],
		], JSON_THROW_ON_ERROR);
		$this->guzzleClient->expects(self::once())
			->method('post')
			->with(
				self::DOCKER_URL . '/' . self::API_VERSION . '/images/prune',
				self::callback(function (array $options) {
					return isset($options['query']['filters'])
						&& $options['query']['filters'] === '{"dangling":["true"]}';
				})
			)
			->willReturn(new Response(200, [], $body));

		$result = $this->dockerActions->pruneImages(self::DOCKER_URL, ['dangling' => ['true']]);

		self::assertSame(104857600, $result['SpaceReclaimed']);
		self::assertCount(2, $result['ImagesDeleted']);
	}

	public function testPruneImagesWithNoFiltersOmitsQueryParam(): void {
		$body = json_encode(['SpaceReclaimed' => 0, 'ImagesDeleted' => null], JSON_THROW_ON_ERROR);
		$this->guzzleClient->expects(self::once())
			->method('post')
			->with(
				self::DOCKER_URL . '/' . self::API_VERSION . '/images/prune',
				self::callback(function (array $options) {
					return !isset($options['query']['filters']);
				})
			)
			->willReturn(new Response(200, [], $body));

		$result = $this->dockerActions->pruneImages(self::DOCKER_URL);

		self::assertSame(0, $result['SpaceReclaimed']);
	}

	public function testPruneImagesReturnsErrorOnGuzzleException(): void {
		$this->guzzleClient->method('post')->willThrowException(
			new ClientException('Forbidden', new Request('POST', ''), new Response(403))
		);

		$result = $this->dockerActions->pruneImages(self::DOCKER_URL, ['dangling' => ['true']]);

		self::assertArrayHasKey('error', $result);
	}

	public function testPruneImagesLogsInfoWithImageCount(): void {
		$body = json_encode([
			'SpaceReclaimed' => 1048576,
			'ImagesDeleted' => [['Deleted' => 'sha256:abc']],
		], JSON_THROW_ON_ERROR);
		$this->guzzleClient->method('post')->willReturn(new Response(200, [], $body));

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('1 images removed'));

		$this->dockerActions->pruneImages(self::DOCKER_URL);
	}

	public function testPruneImagesLogsErrorOnFailure(): void {
		$this->guzzleClient->method('post')->willThrowException(
			new ClientException('Connection refused', new Request('POST', ''), new Response(500))
		);

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('Failed to prune Docker images'));

		$this->dockerActions->pruneImages(self::DOCKER_URL);
	}

	public function testPruneImagesHandlesNullImagesDeleted(): void {
		$body = json_encode(['SpaceReclaimed' => 0, 'ImagesDeleted' => null], JSON_THROW_ON_ERROR);
		$this->guzzleClient->method('post')->willReturn(new Response(200, [], $body));

		$this->logger->expects(self::once())
			->method('info')
			->with(self::stringContains('0 images removed'));

		$result = $this->dockerActions->pruneImages(self::DOCKER_URL);

		self::assertSame(0, $result['SpaceReclaimed']);
	}
}
