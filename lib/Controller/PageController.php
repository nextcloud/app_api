<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OC\App\AppStore\Fetcher\CategoryFetcher;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppScopesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\L10N\IFactory;

class PageController extends Controller {
	public const CACHE_TTL = 60 * 60; // 1 hour
	private IInitialState $initialStateService;
	private IConfig $config;
	private AppAPIService $service;
	private DaemonConfigService $daemonConfigService;
	private ExAppScopesService $exAppScopesService;
	private ICache $cache;
	private DockerActions $dockerActions;
	private CategoryFetcher $categoryFetcher;
	private IFactory $l10nFactory;

	public function __construct(
		IRequest $request,
		IConfig $config,
		ICacheFactory $cacheFactory,
		IInitialState $initialStateService,
		AppAPIService $service,
		DaemonConfigService $daemonConfigService,
		ExAppScopesService $exAppScopesService,
		DockerActions $dockerActions,
		CategoryFetcher $categoryFetcher,
		IFactory $l10nFactory,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->initialStateService = $initialStateService;
		$this->config = $config;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/apps-management');
		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->exAppScopesService = $exAppScopesService;
		$this->dockerActions = $dockerActions;
		$this->categoryFetcher = $categoryFetcher;
		$this->l10nFactory = $l10nFactory;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$apps = $this->loadExAppsData();

		$appInitialData = [
			'appstore_enabled' => $this->config->getSystemValueBool('appstoreenabled', true),
			'apps' => $apps,
			'daemons' => $this->daemonConfigService->getRegisteredDaemonConfigs(),
			'default_daemon_config' => $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', null),
			'categories' => $this->getAllCategories(),
		];

		$this->initialStateService->provideInitialState('apps', $appInitialData);

		return new TemplateResponse(Application::APP_ID, 'main');
	}

	private function loadExAppsData(): array {
		$cacheKey = 'apps-info';
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$apps = $this->service->getExAppsList('all');
		$appsInfo = array_map(function ($app) {
			$exApp = $this->service->getExApp($app['id']);
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
			if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
				$exAppInfo = $this->dockerActions->loadExAppInfo($exApp->getAppid(), $daemonConfig);
			} else {
				$exAppInfo = [];
			}
			return array_merge($app, [
				'daemon_config' => $daemonConfig,
				'appinfo' => $exAppInfo,
				'scopes' => $this->exAppScopesService->getExAppScopes($exApp),
			]);
		}, $apps);
		$this->cache->set($cacheKey, $appsInfo, self::CACHE_TTL);
		return $appsInfo;
	}

	/**
	 * Using default methods to fetch AppStore categories as they are the same for ExApps
	 *
	 * @return mixed
	 */
	private function getAllCategories(): array {
		$currentLang = substr($this->l10nFactory->findLanguage(), 0, 2);
		$categories = $this->categoryFetcher->get();
		return array_map(function (array $category) use ($currentLang) {
			return [
				'id' => $category['id'],
				'name' => $category['translations'][$currentLang]['name'] ?? $category['translations']['en']['name']
			];
		}, $categories);
	}
}
