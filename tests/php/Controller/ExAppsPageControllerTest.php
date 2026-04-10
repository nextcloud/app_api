<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OC\App\AppStore\Fetcher\CategoryFetcher;
use OCA\AppAPI\Controller\ExAppsPageController;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ExAppsPageControllerTest extends TestCase {

	private ExAppsPageController $controller;
	private IFactory&MockObject $l10nFactory;
	private ExAppFetcher&MockObject $exAppFetcher;
	private ExAppService&MockObject $exAppService;
	private DaemonConfigService&MockObject $daemonConfigService;
	private IConfig&MockObject $config;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$service = $this->createMock(AppAPIService::class);
		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$dockerActions = $this->createMock(DockerActions::class);
		$categoryFetcher = $this->createMock(CategoryFetcher::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->exAppFetcher = $this->createMock(ExAppFetcher::class);
		$l10n = $this->createMock(IL10N::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$this->exAppService = $this->createMock(ExAppService::class);
		$exAppDeployOptionsService = $this->createMock(ExAppDeployOptionsService::class);

		$this->controller = new ExAppsPageController(
			$request,
			$this->config,
			$service,
			$this->daemonConfigService,
			$dockerActions,
			$categoryFetcher,
			$this->l10nFactory,
			$this->exAppFetcher,
			$l10n,
			$logger,
			$appManager,
			$this->exAppService,
			$exAppDeployOptionsService,
		);
	}

	private function buildFakeApp(array $overrides = []): array {
		return array_merge([
			'id' => 'fake_app',
			'categories' => ['integration'],
			'releases' => [[
				'version' => '1.0.0',
				'rawPlatformVersionSpec' => '*',
				'rawPhpVersionSpec' => '*',
				'licenses' => ['AGPL'],
			]],
			'authors' => [['name' => 'Test Author']],
			'translations' => [
				'en' => [
					'name' => 'Fake App (en)',
					'description' => 'A fake test app',
					'summary' => 'English summary',
				],
				'de' => [
					'name' => 'Fake App (de)',
					'description' => 'Eine Test-App',
					'summary' => 'Deutsche Zusammenfassung',
				],
			],
			'adminDocs' => '',
			'userDocs' => '',
			'developerDocs' => '',
			'website' => '',
			'issueTracker' => '',
			'isFeatured' => false,
			'screenshots' => [],
			'ratingOverall' => 0,
			'ratingNumOverall' => 0,
		], $overrides);
	}

	/**
	 * Regression test for issue #831.
	 *
	 * The controller used to call \OC::$server->getL10NFactory() on line 120,
	 * which was removed upstream by nextcloud/server#58808. The fix is to use
	 * the already-injected $this->l10nFactory. This test verifies that the
	 * injected factory receives the findLanguage() call during listApps().
	 *
	 * If a regression reintroduces the static accessor, this test will fail:
	 * - on a recent NC master: with a fatal "undefined method" error;
	 * - on an older NC that still has the method: with "expected at least
	 *   once, got 0 times" on the mock expectation below.
	 */
	public function testListAppsUsesInjectedL10NFactory(): void {
		$this->exAppFetcher->method('get')->willReturn([$this->buildFakeApp()]);
		$this->exAppFetcher->method('getExAppsWithUpdates')->willReturn([]);

		$this->exAppService->method('getExApp')->willReturn(null);
		$this->exAppService->method('getExAppsList')->willReturn([]);

		$this->config->method('getSystemValue')
			->with('app_install_overwrite', self::anything())
			->willReturn([]);

		// Key assertion: the injected l10nFactory must receive findLanguage().
		$this->l10nFactory->expects(self::atLeastOnce())
			->method('findLanguage')
			->willReturn('en');

		$response = $this->controller->listApps();

		self::assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		self::assertSame('success', $data['status']);
		self::assertCount(1, $data['apps']);
		self::assertSame('fake_app', $data['apps'][0]['id']);
		self::assertSame('Fake App (en)', $data['apps'][0]['name']);
	}

	/**
	 * Also verify that the selected language actually drives translation
	 * lookup — i.e. that the injected factory's return value is what the
	 * controller uses to pick strings out of $app['translations'].
	 */
	public function testListAppsPicksTranslationForInjectedLanguage(): void {
		$this->exAppFetcher->method('get')->willReturn([$this->buildFakeApp()]);
		$this->exAppFetcher->method('getExAppsWithUpdates')->willReturn([]);

		$this->exAppService->method('getExApp')->willReturn(null);
		$this->exAppService->method('getExAppsList')->willReturn([]);

		$this->config->method('getSystemValue')
			->with('app_install_overwrite', self::anything())
			->willReturn([]);

		$this->l10nFactory->method('findLanguage')->willReturn('de_DE');

		$response = $this->controller->listApps();
		$data = $response->getData();

		self::assertCount(1, $data['apps']);
		self::assertSame('Fake App (de)', $data['apps'][0]['name']);
		self::assertSame('Eine Test-App', $data['apps'][0]['description']);
	}
}
