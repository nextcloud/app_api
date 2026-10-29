<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Service\UI\ReferenceProviderService;
use OCA\AppAPI\Tests\php\Controller\RegistersFakeExAppTrait;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('DB')]
class ReferenceProviderServiceTest extends TestCase {
	use RegistersFakeExAppTrait;

	private const APP_A = 'phpunit_refprov_a';
	private const APP_B = 'phpunit_refprov_b';
	private const APP_DISABLED = 'phpunit_refprov_off';

	private ReferenceProviderService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(ReferenceProviderService::class);
		$this->insertFakeExApp(self::APP_A, 23101);
		$this->insertFakeExApp(self::APP_B, 23102);
		$this->insertFakeExApp(self::APP_DISABLED, 23103, enabled: 0);
	}

	protected function tearDown(): void {
		foreach ([self::APP_A, self::APP_B, self::APP_DISABLED] as $appId) {
			$this->service->unregisterExAppReferenceProviders($appId);
			$this->deleteFakeExApp($appId);
		}
		parent::tearDown();
	}

	private function register(string $appId, string $providerId, array $patterns = [], array $search = []): void {
		self::assertNotNull($this->service->registerReferenceProvider(
			$appId, $providerId, 'Title ' . $providerId, '', 10, $search, $patterns, 'js/picker.js'));
	}

	/** @return string[] */
	private function enabledIds(): array {
		return array_map(static fn ($p) => $p->getProviderId(), $this->service->getEnabledReferenceProviders());
	}

	public function testOnlyProvidersOfEnabledExAppsAreListed(): void {
		$this->register(self::APP_A, 'phpunit_rp_a');
		$this->register(self::APP_DISABLED, 'phpunit_rp_off');

		$ids = $this->enabledIds();
		self::assertContains('phpunit_rp_a', $ids);
		self::assertNotContains('phpunit_rp_off', $ids);
	}

	public function testListingReflectsUnregisterDespiteCache(): void {
		$this->register(self::APP_A, 'phpunit_rp_a');
		self::assertContains('phpunit_rp_a', $this->enabledIds());

		self::assertTrue($this->service->unregisterReferenceProvider(self::APP_A, 'phpunit_rp_a'));
		self::assertNotContains('phpunit_rp_a', $this->enabledIds());
	}

	public function testProviderIdOfAnotherAppIsRejected(): void {
		$this->register(self::APP_A, 'phpunit_rp_shared');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->registerReferenceProvider(self::APP_B, 'phpunit_rp_shared', 'Hijack', '', 0, [], [], '');
	}

	public function testOtherAppCannotReadOrDeleteProvider(): void {
		$this->register(self::APP_A, 'phpunit_rp_a');

		self::assertNull($this->service->getReferenceProvider(self::APP_B, 'phpunit_rp_a'));
		self::assertFalse($this->service->unregisterReferenceProvider(self::APP_B, 'phpunit_rp_a'));
		self::assertNotNull($this->service->getReferenceProvider(self::APP_A, 'phpunit_rp_a'));
	}

	public function testInvalidPatternIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->registerReferenceProvider(self::APP_A, 'phpunit_rp_bad', 'Bad', '', 0, [], ['/unclosed('], '');
	}

	public function testInvalidProviderIdIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->registerReferenceProvider(self::APP_A, 'has space', 'Bad', '', 0, [], [], '');
	}

	/**
	 * Each of these, appended to /exapps/<appid>/, makes the browser load a file outside the ExApp:
	 * another ExApp's script (`..`, encoded or not) or another origin (`\host`, schemes).
	 */
	public static function escapingPaths(): array {
		return [
			'parent' => ['../other_app/js/x.js'],
			'nested parent' => ['js/../../other_app/x.js'],
			'encoded parent' => ['js/%2e%2e/x.js'],
			'encoded slash' => ['js%2f..%2fx.js'],
			'backslash host' => ['\\evil.example/x.js'],
			'scheme' => ['https://evil.example/x.js'],
			'javascript' => ['javascript:alert(1)'],
			'query' => ['js/x.js?x=1'],
			'empty segment' => ['js//x.js'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('escapingPaths')]
	public function testScriptPathEscapingTheExAppIsRejected(string $path): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->registerReferenceProvider(self::APP_A, 'phpunit_rp_bad', 'Bad', '', 0, [], [], $path);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('escapingPaths')]
	public function testRelativeIconUrlEscapingTheExAppIsRejected(string $path): void {
		if (str_starts_with($path, 'https://')) {
			self::assertNotNull($this->service->registerReferenceProvider(self::APP_A, 'phpunit_rp_bad', 'Ok', $path, 0, [], [], ''));
			return; // absolute http(s) icons are allowed
		}
		$this->expectException(\InvalidArgumentException::class);
		$this->service->registerReferenceProvider(self::APP_A, 'phpunit_rp_bad', 'Bad', $path, 0, [], [], '');
	}

	public function testScriptPathInsideTheExAppIsAccepted(): void {
		$row = $this->service->registerReferenceProvider(
			self::APP_A, 'phpunit_rp_a', 'Ok', '/img/app.svg', 0, [], [], '/js/caption_obvious-reference.js');
		self::assertSame('js/caption_obvious-reference.js', $row->getScriptPath());
	}

	public function testReRegisterReplacesFields(): void {
		$this->register(self::APP_A, 'phpunit_rp_a', ['#^https://a\.test/#']);
		$this->service->registerReferenceProvider(self::APP_A, 'phpunit_rp_a', 'Renamed', '', 5, [], ['#^https://b\.test/#'], '');

		$row = $this->service->getReferenceProvider(self::APP_A, 'phpunit_rp_a');
		self::assertSame('Renamed', $row->getTitle());
		self::assertSame(['#^https://b\.test/#'], $row->getMatchPatternsList());
		self::assertSame('', $row->getScriptPath());
	}

	public function testAnonymousProviderMatchesOnlyItsPatterns(): void {
		$this->register(self::APP_A, 'phpunit_rp_a', ['#^https://a\.test/item/\d+$#', '#^https://alt\.test/#'], ['phpunit-search']);
		$provider = $this->service->getAnonymousReferenceProvider(
			$this->service->getReferenceProvider(self::APP_A, 'phpunit_rp_a'));

		self::assertSame('phpunit_rp_a', $provider->getId());
		self::assertSame('Title phpunit_rp_a', $provider->getTitle());
		self::assertSame(10, $provider->getOrder());
		self::assertInstanceOf(ISearchableReferenceProvider::class, $provider);
		self::assertSame(['phpunit-search'], $provider->getSupportedSearchProviderIds());

		self::assertTrue($provider->matchReference('https://a.test/item/42'));
		self::assertTrue($provider->matchReference('https://alt.test/anything'));
		self::assertFalse($provider->matchReference('https://a.test/item/42/extra'));
		self::assertFalse($provider->matchReference('https://other.test/item/42'));
	}

	public function testProviderWithoutPatternsMatchesNothingAndResolvesNothing(): void {
		$this->register(self::APP_A, 'phpunit_rp_a');
		$provider = $this->service->getAnonymousReferenceProvider(
			$this->service->getReferenceProvider(self::APP_A, 'phpunit_rp_a'));

		self::assertFalse($provider->matchReference('https://a.test/item/1'));
		// No request to the (non-existent) ExApp for a link that does not match.
		self::assertNull($provider->resolveReference('https://a.test/item/1'));
	}

	public function testCachePrefixSeparatesLinks(): void {
		$this->register(self::APP_A, 'phpunit_rp_a', ['#^https://a\.test/#']);
		$provider = $this->service->getAnonymousReferenceProvider(
			$this->service->getReferenceProvider(self::APP_A, 'phpunit_rp_a'));

		self::assertNotSame(
			$provider->getCachePrefix('https://a.test/1'),
			$provider->getCachePrefix('https://a.test/2'),
		);
	}

	public function testUnregisterAllRemovesOnlyThatApp(): void {
		$this->register(self::APP_A, 'phpunit_rp_a1');
		$this->register(self::APP_A, 'phpunit_rp_a2');
		$this->register(self::APP_B, 'phpunit_rp_b');

		self::assertSame(2, $this->service->unregisterExAppReferenceProviders(self::APP_A));
		$ids = $this->enabledIds();
		self::assertNotContains('phpunit_rp_a1', $ids);
		self::assertContains('phpunit_rp_b', $ids);
	}
}
