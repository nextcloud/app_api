<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\UI\ReferenceProviderService;
use OCA\AppAPI\Tests\php\Controller\RegistersFakeExAppTrait;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Http\Client\IResponse;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * What the server receives from an ExApp's `/reference/resolve` answer, with the ExApp request stubbed.
 */
#[Group('DB')]
class ReferenceProviderResolveTest extends TestCase {
	use RegistersFakeExAppTrait;

	private const APP = 'phpunit_refprov_resolve';
	private const PROVIDER = 'phpunit_rp_resolve';

	private ReferenceProviderService $service;
	/** @var AppAPIService&MockObject */
	private AppAPIService $appApi;

	protected function setUp(): void {
		parent::setUp();
		$this->insertFakeExApp(self::APP, 23111);
		$this->service = Server::get(ReferenceProviderService::class);
		$this->service->registerReferenceProvider(self::APP, self::PROVIDER, 'Resolve', '', 0, [], ['#^https://resolve\.test/#'], '');
		$this->appApi = $this->createMock(AppAPIService::class);
		$this->overwriteService(AppAPIService::class, $this->appApi);
	}

	protected function tearDown(): void {
		$this->restoreAllServices();
		$this->service->unregisterExAppReferenceProviders(self::APP);
		$this->deleteFakeExApp(self::APP);
		parent::tearDown();
	}

	private function provider(): IReferenceProvider {
		return $this->service->getAnonymousReferenceProvider($this->service->getReferenceProvider(self::APP, self::PROVIDER));
	}

	private function answer(array $body, int $status = 200): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($body));
		$this->appApi->method('requestToExApp')->willReturn($response);
	}

	public function testUrlsThatAreNotHttpAreDropped(): void {
		$this->answer([
			'title' => 'x',
			'url' => 'javascript:alert(document.cookie)',
			'image_url' => 'data:image/svg+xml,<svg onload=alert(1)>',
		]);
		$link = 'https://resolve.test/a';
		$reference = $this->provider()->resolveReference($link);

		self::assertNotNull($reference);
		self::assertSame($link, $reference->getUrl(), 'falls back to the link itself');
		self::assertNull($reference->getImageUrl());
	}

	public function testHttpUrlsAreKept(): void {
		$this->answer(['url' => 'https://resolve.test/canonical', 'image_url' => 'http://img.resolve.test/a.png']);
		$reference = $this->provider()->resolveReference('https://resolve.test/b');

		self::assertSame('https://resolve.test/canonical', $reference->getUrl());
		self::assertSame('http://img.resolve.test/a.png', $reference->getImageUrl());
	}

	public function testOversizedOrInvalidRichObjectIsDropped(): void {
		$this->answer(['rich_object_type' => 'big', 'rich_object' => ['blob' => str_repeat('x', 20000)]]);
		// No rich object: the reference falls back to the plain open-graph preview.
		self::assertSame('open-graph', $this->provider()->resolveReference('https://resolve.test/c')->getRichObjectType());
	}

	public function testRichObjectTypeWithMarkupIsDropped(): void {
		$this->answer(['rich_object_type' => '<img src=x>', 'rich_object' => ['a' => 1]]);
		self::assertSame('open-graph', $this->provider()->resolveReference('https://resolve.test/d')->getRichObjectType());
	}

	public function testValidRichObjectIsKept(): void {
		$this->answer(['rich_object_type' => 'phpunit_meme', 'rich_object' => ['slug' => 'abc']]);
		$reference = $this->provider()->resolveReference('https://resolve.test/e');
		self::assertSame('phpunit_meme', $reference->getRichObjectType());
		self::assertSame(['slug' => 'abc'], $reference->getRichObject());
	}

	public function testFailureIsRememberedSoTheExAppIsNotCalledAgain(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$this->appApi->expects(self::once())->method('requestToExApp')->willReturn($response);
		$link = 'https://resolve.test/broken/' . uniqid();

		self::assertNull($this->provider()->resolveReference($link));
		self::assertNull($this->provider()->resolveReference($link), 'second render is answered from the failure cache');
	}

	public function testRequestUsesABoundedTimeout(): void {
		$this->appApi->expects(self::once())->method('requestToExApp')
			->with(self::anything(), '/reference/resolve', self::anything(), 'POST', self::anything(),
				self::callback(static fn (array $options) => ($options['timeout'] ?? 0) === ReferenceProviderService::RESOLVE_TIMEOUT))
			->willReturn(['error' => 'stub']);
		$this->provider()->resolveReference('https://resolve.test/timeout/' . uniqid());
	}
}
