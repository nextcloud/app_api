<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OC\App\AppStore\Fetcher\CategoryFetcher;
use OC\App\AppStore\Version\VersionParser;
use OC\App\DependencyAnalyzer;
use OC\App\Platform;
use OC_App;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppScope;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppApiScopeService;
use OCA\AppAPI\Service\ExAppScopesService;
use OCA\AppAPI\Service\ExAppUsersService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * ExApps actions controller similar to default one with project-specific changes and additions
 */
class ExAppsPageController extends Controller {
	private IInitialState $initialStateService;
	private IConfig $config;
	private AppAPIService $service;
	private DaemonConfigService $daemonConfigService;
	private ExAppScopesService $exAppScopeService;
	private DockerActions $dockerActions;
	private CategoryFetcher $categoryFetcher;
	private IFactory $l10nFactory;
	private ExAppFetcher $exAppFetcher;
	private ExAppApiScopeService $exAppApiScopeService;
	private IL10N $l10n;
	private LoggerInterface $logger;
	private IAppManager $appManager;
	private ExAppUsersService $exAppUsersService;

	public function __construct(
		IRequest $request,
		IConfig $config,
		IInitialState $initialStateService,
		AppAPIService $service,
		DaemonConfigService $daemonConfigService,
		ExAppScopesService $exAppScopeService,
		ExAppApiScopeService $exAppApiScopeService,
		ExAppUsersService $exAppUsersService,
		DockerActions $dockerActions,
		CategoryFetcher $categoryFetcher,
		IFactory $l10nFactory,
		ExAppFetcher $exAppFetcher,
		IL10N $l10n,
		LoggerInterface $logger,
		IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->initialStateService = $initialStateService;
		$this->config = $config;
		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->exAppScopeService = $exAppScopeService;
		$this->exAppApiScopeService = $exAppApiScopeService;
		$this->dockerActions = $dockerActions;
		$this->categoryFetcher = $categoryFetcher;
		$this->l10nFactory = $l10nFactory;
		$this->l10n = $l10n;
		$this->exAppFetcher = $exAppFetcher;
		$this->logger = $logger;
		$this->appManager = $appManager;
		$this->exAppUsersService = $exAppUsersService;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	#[NoCSRFRequired]
	public function viewApps(): TemplateResponse {
		$defaultDaemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', '');

		$appInitialData = [
			'appstoreEnabled' => $this->config->getSystemValueBool('appstoreenabled', true),
			'updateCount' => count($this->getExAppsWithUpdates()),
			'default_daemon_config' => $defaultDaemonConfigName,
			'docker_socket_accessible' => $this->dockerActions->isDockerSocketAvailable(),
		];

		if ($defaultDaemonConfigName !== '') {
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($defaultDaemonConfigName);
			$appInitialData['daemon_config'] = $daemonConfig;
		}

		$this->initialStateService->provideInitialState('apps', $appInitialData);

		$templateResponse = new TemplateResponse(Application::APP_ID, 'main');
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedImageDomain('https://usercontent.apps.nextcloud.com');
		$templateResponse->setContentSecurityPolicy($policy);

		return $templateResponse;
	}

	private function getExAppsWithUpdates(): array {
		$apps = $this->exAppFetcher->get();
		$appsWithUpdates = array_filter($apps, function (array $app) {
			$exApp = $this->service->getExApp($app['id']);
			$newestVersion = $app['releases'][0]['version'];
			return $exApp !== null && isset($app['releases'][0]['version']) && version_compare($newestVersion, $exApp->getVersion(), '>');
		});
		return array_values($appsWithUpdates);
	}

	/**
	 * Using the same algorithm of ExApps listing as for regular apps.
	 * Returns all apps for a category from the App Store
	 *
	 * @param string $requestedCategory
	 * @return array
	 *
	 * @throws \Exception
	 */
	private function getAppsForCategory(string $requestedCategory = ''): array {
		$versionParser = new VersionParser();
		$formattedApps = [];
		$apps = $this->exAppFetcher->get();
		foreach ($apps as $app) {
			$exApp = $this->service->getExApp($app['id']);

			// Skip all apps not in the requested category
			if ($requestedCategory !== '') {
				$isInCategory = false;
				foreach ($app['categories'] as $category) {
					if ($category === $requestedCategory) {
						$isInCategory = true;
					}
				}
				if (!$isInCategory) {
					continue;
				}
			}

			// Default PHP and NC versions requirements check preserved
			if (!isset($app['releases'][0]['rawPlatformVersionSpec'])) {
				continue;
			}
			$nextCloudVersion = $versionParser->getVersion($app['releases'][0]['rawPlatformVersionSpec']);
			$nextCloudVersionDependencies = [];
			if ($nextCloudVersion->getMinimumVersion() !== '') {
				$nextCloudVersionDependencies['nextcloud']['@attributes']['min-version'] = $nextCloudVersion->getMinimumVersion();
			}
			if ($nextCloudVersion->getMaximumVersion() !== '') {
				$nextCloudVersionDependencies['nextcloud']['@attributes']['max-version'] = $nextCloudVersion->getMaximumVersion();
			}
			$phpVersion = $versionParser->getVersion($app['releases'][0]['rawPhpVersionSpec']);
			$existsLocally = $exApp !== null;
			$phpDependencies = [];
			if ($phpVersion->getMinimumVersion() !== '') {
				$phpDependencies['php']['@attributes']['min-version'] = $phpVersion->getMinimumVersion();
			}
			if ($phpVersion->getMaximumVersion() !== '') {
				$phpDependencies['php']['@attributes']['max-version'] = $phpVersion->getMaximumVersion();
			}
			if (isset($app['releases'][0]['minIntSize'])) {
				$phpDependencies['php']['@attributes']['min-int-size'] = $app['releases'][0]['minIntSize'];
			}
			$authors = '';
			foreach ($app['authors'] as $key => $author) {
				$authors .= $author['name'];
				if ($key !== count($app['authors']) - 1) {
					$authors .= ', ';
				}
			}

			$currentLanguage = substr(\OC::$server->getL10NFactory()->findLanguage(), 0, 2);
			$enabledValue = $this->config->getAppValue($app['id'], 'enabled', 'no');
			$groups = null;
			if ($enabledValue !== 'no' && $enabledValue !== 'yes') {
				$groups = $enabledValue;
			}

			if ($exApp !== null) {
				$currentVersion = $exApp->getVersion();
			} else {
				$currentVersion = $app['releases'][0]['version'];
			}

			$scopes = null;
			$daemon = null;

			if ($exApp !== null) {
				$scopes = $this->exAppApiScopeService->mapScopeGroupsToNames(array_map(function (ExAppScope $exAppScope) {
					return $exAppScope->getScopeGroup();
				}, $this->exAppScopeService->getExAppScopes($exApp)));
				$daemon = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
			}

			$formattedApps[] = [
				'id' => $app['id'],
				'installed' => $exApp !== null, // if ExApp registered then it's assumed that it was already deployed (installed)
				'appstore' => true,
				'name' => $app['translations'][$currentLanguage]['name'] ?? $app['translations']['en']['name'],
				'description' => $app['translations'][$currentLanguage]['description'] ?? $app['translations']['en']['description'],
				'summary' => $app['translations'][$currentLanguage]['summary'] ?? $app['translations']['en']['summary'],
				'license' => $app['releases'][0]['licenses'],
				'author' => $authors,
				'shipped' => false,
				'version' => $currentVersion,
				'types' => [],
				'documentation' => [
					'admin' => $app['adminDocs'],
					'user' => $app['userDocs'],
					'developer' => $app['developerDocs']
				],
				'website' => $app['website'],
				'bugs' => $app['issueTracker'],
				'detailpage' => $app['website'],
				'dependencies' => array_merge(
					$nextCloudVersionDependencies,
					$phpDependencies
				),
				'level' => ($app['isFeatured'] === true) ? 200 : 100,
				'missingMaxOwnCloudVersion' => false,
				'missingMinOwnCloudVersion' => false,
				'canInstall' => true,
				'screenshot' => isset($app['screenshots'][0]['url']) ? 'https://usercontent.apps.nextcloud.com/' . base64_encode($app['screenshots'][0]['url']) : '',
				'score' => $app['ratingOverall'],
				'ratingNumOverall' => $app['ratingNumOverall'],
				'ratingNumThresholdReached' => $app['ratingNumOverall'] > 5,
				'removable' => $existsLocally,
				'active' => $exApp !== null && $exApp->getEnabled() === 1,
				'needsDownload' => !$existsLocally,
				'groups' => $groups,
				'fromAppStore' => true,
				'appstoreData' => $app,
				'scopes' => $scopes,
				'daemon' => $daemon,
				'systemApp' => $exApp !== null && $this->exAppUsersService->exAppUserExists($exApp->getAppid(), ''),
				'exAppUrl' => $exApp !== null ? AppAPIService::getExAppUrl($exApp->getProtocol(), $exApp->getHost(), $exApp->getPort()) : '',
			];
		}

		return $formattedApps;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function listApps(): JSONResponse {
		$apps = $this->getAppsForCategory('');
		$appsWithUpdate = $this->getExAppsWithUpdates();

		$exApps = $this->service->getExAppsList('all');
		$dependencyAnalyzer = new DependencyAnalyzer(new Platform($this->config), $this->l10n);

		$ignoreMaxApps = $this->config->getSystemValue('app_install_overwrite', []);
		if (!is_array($ignoreMaxApps)) {
			$this->logger->warning('The value given for app_install_overwrite is not an array. Ignoring...');
			$ignoreMaxApps = [];
		}

		// Extend existing app details
		$apps = array_map(function (array $appData) use ($appsWithUpdate, $dependencyAnalyzer, $ignoreMaxApps) {
			if (isset($appData['appstoreData'])) {
				$appstoreData = $appData['appstoreData'];
				$appData['screenshot'] = isset($appstoreData['screenshots'][0]['url']) ? 'https://usercontent.apps.nextcloud.com/' . base64_encode($appstoreData['screenshots'][0]['url']) : '';
				$appData['category'] = $appstoreData['categories'];
				$appData['releases'] = $appstoreData['releases'];
			}

			$appIdsWithUpdate = array_map(function (array $appWithUpdate) {
				return $appWithUpdate['id'];
			}, $appsWithUpdate);
			if (in_array($appData['id'], $appIdsWithUpdate)) {
				$appData['update'] = array_values(array_filter($appsWithUpdate, function ($appUpdate) use ($appData) {
					return $appUpdate['id'] === $appData['id'];
				}))[0]['releases'][0]['version'];
			}

			// fix groups to be an array
			$groups = [];
			if (is_string($appData['groups'])) {
				$groups = json_decode($appData['groups']);
			}
			$appData['groups'] = $groups;
			$appData['canUnInstall'] = !$appData['active'] && $appData['removable'];

			// fix licence vs license
			if (isset($appData['license']) && !isset($appData['licence'])) {
				$appData['licence'] = $appData['license'];
			}

			$ignoreMax = in_array($appData['id'], $ignoreMaxApps);

			// analyse dependencies
			$missing = $dependencyAnalyzer->analyze($appData, $ignoreMax);
			$appData['canInstall'] = empty($missing);
			$appData['missingDependencies'] = $missing;

			$appData['missingMinOwnCloudVersion'] = !isset($appData['dependencies']['nextcloud']['@attributes']['min-version']);
			$appData['missingMaxOwnCloudVersion'] = !isset($appData['dependencies']['nextcloud']['@attributes']['max-version']);
			$appData['isCompatible'] = $dependencyAnalyzer->isMarkedCompatible($appData);

			return $appData;
		}, $apps);

		$apps = $this->buildLocalAppsList($apps, $exApps);

		usort($apps, [$this, 'sortApps']);

		return new JSONResponse(['apps' => $apps, 'status' => 'success']);
	}

	/**
	 * Prepare list of local ExApps with required data structure to be listed in UI.
	 * This is a temporal solution to display local apps with lack of information about it until
	 * it's support extended.
	 *
	 * @param array $apps Formatted list of apps available in App Store
	 * @param array $exApps List of registered ExApps (could be not listed in App Store)
	 *
	 * @return array
	 */
	private function buildLocalAppsList(array $apps, array $exApps): array {
		$registeredAppsIds = array_map(function ($app) {
			return $app['id'];
		}, $apps);
		$formattedLocalApps = [];
		foreach ($exApps as $app) {
			if (!in_array($app['id'], $registeredAppsIds)) {
				$exApp = $this->service->getExApp($app['id']);
				$daemon = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
				$scopes = $this->exAppApiScopeService->mapScopeGroupsToNames(array_map(function (ExAppScope $exAppScope) {
					return $exAppScope->getScopeGroup();
				}, $this->exAppScopeService->getExAppScopes($exApp)));

				$formattedLocalApps[] = [
					'id' => $app['id'],
					'appstore' => false,
					'installed' => true,
					'name' => $exApp->getName(),
					'description' => '',
					'summary' => '',
					'license' => '',
					'author' => '',
					'shipped' => false,
					'version' => $exApp->getVersion(),
					'types' => [],
					'documentation' => [
						'admin' => '',
						'user' => '',
						'developer' => ''
					],
					'website' => '',
					'bugs' => '',
					'detailpage' => '',
					'dependencies' => [],
					'level' => 100,
					'missingMaxOwnCloudVersion' => false,
					'missingMinOwnCloudVersion' => false,
					'canInstall' => true,
					'canUnInstall' => false,
					'isCompatible' => true,
					'screenshot' => '',
					'score' => 0,
					'ratingNumOverall' => 0,
					'ratingNumThresholdReached' => false,
					'removable' => false,
					'active' => $exApp->getEnabled() === 1,
					'needsDownload' => false,
					'groups' => [],
					'fromAppStore' => false,
					'appstoreData' => $app,
					'scopes' => $scopes,
					'daemon' => $daemon,
					'systemApp' => $this->exAppUsersService->exAppUserExists($exApp->getAppid(), ''),
					'exAppUrl' => AppAPIService::getExAppUrl($exApp->getProtocol(), $exApp->getHost(), $exApp->getPort()),
					'releases' => [],
					'update' => null,
				];
			}
		}
		$apps = array_merge($apps, $formattedLocalApps);
		return $apps;
	}

	/**
	 * @PasswordConfirmationRequired
	 *
	 * @param string $appId
	 * @param array $groups // TODO: Add support of groups later if needed
	 *
	 * @return JSONResponse
	 */
	public function enableApp(string $appId, array $groups = []): JSONResponse {
		return $this->enableApps([$appId]);
	}

	/**
	 * Enable one or more apps.
	 * Deploy ExApp if it was not deployed yet.
	 *
	 * @PasswordConfirmationRequired
	 * @param array $appIds
	 * @param array $groups
	 * @return JSONResponse
	 */
	public function enableApps(array $appIds, array $groups = []): JSONResponse {
		try {
			$updateRequired = false;

			foreach ($appIds as $appId) {
				// If ExApp is not null, assuming it was already deployed, therefore it could be registered
				$exApp = $this->service->getExApp($appId);

				// If ExApp not registered - then it's a "Deploy and Enable" action. Get default_daemon_config, deploy ExApp, register and finally enable
				if ($exApp === null) {
					$infoXml = $this->service->getLatestExAppInfoFromAppstore($appId);
					$defaultDaemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', '');
					$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($defaultDaemonConfigName);
					if ($daemonConfig->getAcceptsDeployId() !== $this->dockerActions->getAcceptsDeployId()) {
						return new JSONResponse(['data' => ['message' => $this->l10n->t('Only docker-install is supported for now')]], Http::STATUS_INTERNAL_SERVER_ERROR);
					}
					// 1. Deploy ExApp
					if ($this->deployExApp($appId, $infoXml, $daemonConfig)) {
						// 2. Register ExApp (container must be already initialized successfully)
						if (!$this->registerExApp($appId, $infoXml, $daemonConfig)) {
							$this->service->unregisterExApp($appId); // Fallback unregister if failure
						}
					} else {
						$this->logger->error('Failed to Deploy ExApp');
						return new JSONResponse([
							'data' => [
								'message' => 'Failed to Deploy ExApp',
							]
						], Http::STATUS_INTERNAL_SERVER_ERROR);
					}

					$exApp = $this->service->getExApp($appId);
					$this->service->enableExApp($exApp);
					return new JSONResponse([
						'data' => [
							'update_required' => $updateRequired,
							'daemon_config' => $daemonConfig,
							'systemApp' => $this->exAppUsersService->exAppUserExists($exApp->getAppid(), ''),
							'exAppUrl' => AppAPIService::getExAppUrl($exApp->getProtocol(), $exApp->getHost(), $exApp->getPort()),
						]
					]);
				}

				$appsWithUpdate = $this->getExAppsWithUpdates();
				$appIdsWithUpdate = array_map(function (array $appWithUpdate) {
					return $appWithUpdate['id'];
				}, $appsWithUpdate);

				if (in_array($appId, $appIdsWithUpdate)) {
					$updateRequired = true;
				}

				if (!$this->service->enableExApp($exApp)) {
					return new JSONResponse(['data' => ['message' => 'Failed to enable ExApp']], Http::STATUS_INTERNAL_SERVER_ERROR);
				}
			}

			return new JSONResponse(['data' => ['update_required' => $updateRequired]]);
		} catch (\Exception $e) {
			$this->logger->error('Could not enable ExApps', ['exception' => $e]);
			return new JSONResponse(['data' => ['message' => $e->getMessage()]], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function deployExApp(string $appId, \SimpleXMLElement $infoXml, DaemonConfig $daemonConfig): bool {
		$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $infoXml);
		[$pullResult, $createResult, $startResult] = $this->dockerActions->deployExApp($daemonConfig, $deployParams);

		if (isset($pullResult['error']) || isset($createResult['error']) || isset($startResult['error'])) {
			return false;
		}

		if (!$this->dockerActions->healthcheckContainer($this->dockerActions->buildExAppContainerName($appId), $daemonConfig)) {
			return false;
		}

		if (!$this->service->heartbeatExApp([
			'protocol' => (string) ($infoXml->xpath('ex-app/protocol')[0] ?? 'http'),
			'host' => $this->dockerActions->resolveDeployExAppHost($appId, $daemonConfig),
			'port' => explode('=', $deployParams['container_params']['env'][7])[1],
		])) {
			return false;
		}

		return true;
	}

	private function registerExApp(string $appId, \SimpleXMLElement $infoXml, DaemonConfig $daemonConfig): bool {
		$exAppInfo = $this->dockerActions->loadExAppInfo($appId, $daemonConfig);

		$exApp = $this->service->registerExApp($appId, [
			'appid' => $exAppInfo['appid'],
			'version' => $exAppInfo['version'],
			'name' => $exAppInfo['name'],
			'daemon_config_name' => $daemonConfig->getName(),
			'protocol' => $exAppInfo['protocol'] ?? 'http',
			'port' => (int) $exAppInfo['port'],
			'host' => $exAppInfo['host'],
			'secret' => $exAppInfo['secret'],
		]);

		if ($exApp === null) {
			return false;
		}

		// Setup system flag
		$isSystemApp = $this->exAppUsersService->exAppUserExists($exApp->getAppid(), '');
		if (filter_var($exAppInfo['system_app'], FILTER_VALIDATE_BOOLEAN) && !$isSystemApp) {
			$this->exAppUsersService->setupSystemAppFlag($exApp);
		}

		// Register ExApp ApiScopes
		$requestedExAppScopeGroups = $this->service->getExAppRequestedScopes($exApp, $infoXml, $exAppInfo);
		if (!$this->registerApiScopes($exApp, $requestedExAppScopeGroups, 'required')) {
			return false;
		}
		$this->registerApiScopes($exApp, $requestedExAppScopeGroups, 'optional');

		return true;
	}

	private function registerApiScopes(ExApp $exApp, array $requestedExAppScopeGroups, string $scopeType): bool {
		$registeredScopeGroups = [];
		foreach ($this->exAppApiScopeService->mapScopeNamesToNumbers($requestedExAppScopeGroups[$scopeType]) as $scopeGroup) {
			if ($this->exAppScopeService->setExAppScopeGroup($exApp, $scopeGroup)) {
				$registeredScopeGroups[] = $scopeGroup;
			}
		}
		if (count($registeredScopeGroups) !== count($requestedExAppScopeGroups['required'])) {
			return false;
		}
		return true;
	}

	/**
	 * @PasswordConfirmationRequired
	 *
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	#[PasswordConfirmationRequired]
	public function disableApp(string $appId): JSONResponse {
		return $this->disableApps([$appId]);
	}

	/**
	 * @PasswordConfirmationRequired
	 *
	 * @param array $appIds
	 *
	 * @return JSONResponse
	 */
	#[PasswordConfirmationRequired]
	public function disableApps(array $appIds): JSONResponse {
		try {
			foreach ($appIds as $appId) {
				$exApp = $this->service->getExApp($appId);
				if (!$this->service->disableExApp($exApp)) {
					return new JSONResponse(['data' => ['message' => 'Failed to disable ExApp']], Http::STATUS_INTERNAL_SERVER_ERROR);
				}
			}
			return new JSONResponse([]);
		} catch (\Exception $e) {
			$this->logger->error('Could not disable ExApp', ['exception' => $e]);
			return new JSONResponse(['data' => ['message' => $e->getMessage()]], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Default forced ExApp update process.
	 * Update approval via password confirmation.
	 * Scopes approval does not applied in UI for now.
	 *
	 * @PasswordConfirmationRequired
	 * @NoCSRFRequired
	 */
	#[PasswordConfirmationRequired]
	public function updateApp(string $appId): JSONResponse {
		$appsWithUpdate = $this->getExAppsWithUpdates();
		$appIdsWithUpdate = array_map(function (array $appWithUpdate) {
			return $appWithUpdate['id'];
		}, $appsWithUpdate);
		if (!in_array($appId, $appIdsWithUpdate)) {
			return new JSONResponse(['data' => ['message' => $this->l10n->t('Could not update ExApp')]], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$exApp = $this->service->getExApp($appId);

		// TODO: Add error messages on each step failure as in CLI

		// 1. Disable ExApp
		$this->service->disableExApp($exApp);

		$infoXml = $this->service->getLatestExAppInfoFromAppstore($appId);
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig->getAcceptsDeployId() !== $this->dockerActions->getAcceptsDeployId()) {
			return new JSONResponse(['data' => ['message' => $this->l10n->t('Only docker-install is supported for now')]], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->dockerActions->initGuzzleClient($daemonConfig);
		$containerInfo = $this->dockerActions->inspectContainer($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppContainerName($appId));

		$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $infoXml, [
			'container_info' => $containerInfo,
		]);
		// 2. Update ExApp container (deploy new version)
		$this->dockerActions->updateExApp($daemonConfig, $deployParams);

		// 3. Update ExApp info on NC side
		$exAppInfo = $this->dockerActions->loadExAppInfo($appId, $daemonConfig);
		$this->service->updateExAppInfo($exApp, $exAppInfo);

		// 4. Update ExApp ApiScopes
		$this->upgradeExAppScopes($exApp, $infoXml);

		$exApp = $this->service->getExApp($appId);
		// 5. Heartbeat ExApp
		if ($this->service->heartbeatExApp([
			'protocol' => $exAppInfo['protocol'],
			'host' => $exAppInfo['host'],
			'port' => (int) $exAppInfo['port'],
		])) {
			// 6. Enable ExApp
			$this->service->enableExApp($exApp);
		}

		return new JSONResponse([
			'data' => [
				'appid' => $appId,
				'systemApp' => filter_var($exAppInfo['system_app'], FILTER_VALIDATE_BOOLEAN),
				'exAppUrl' => AppAPIService::getExAppUrl($exAppInfo['protocol'], $exAppInfo['host'], (int) $exAppInfo['port']),
			]
		]);
	}

	/**
	 * @param ExApp $exApp
	 * @param \SimpleXMLElement $infoXml
	 *
	 * @return void
	 */
	private function upgradeExAppScopes(ExApp $exApp, \SimpleXMLElement $infoXml): void {
		$newExAppScopes = $this->service->getExAppRequestedScopes($exApp, $infoXml);

		$newExAppScopes = array_merge(
			$this->exAppApiScopeService->mapScopeNamesToNumbers($newExAppScopes['required']),
			$this->exAppApiScopeService->mapScopeNamesToNumbers($newExAppScopes['optional'])
		);

		$this->exAppScopeService->updateExAppScopes($exApp, $newExAppScopes);
	}

	/**
	 * Unregister ExApp, remove container and volume by default
	 *
	 * @PasswordConfirmationRequired
	 */
	#[PasswordConfirmationRequired]
	public function uninstallApp(string $appId, bool $removeContainer = true, bool $removeData = true): JSONResponse {
		$exApp = $this->service->getExApp($appId);
		$this->service->disableExApp($exApp);

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$this->dockerActions->initGuzzleClient($daemonConfig);
			if ($removeContainer) {
				$this->dockerActions->removePrevExAppContainer($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppContainerName($appId));
				if ($removeData) {
					$this->dockerActions->removeVolume($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppVolumeName($appId));
				}
			}
		}

		$this->service->unregisterExApp($appId);
		return new JSONResponse();
	}

	/**
	 * Using default force mechanism for ExApps
	 *
	 * @PasswordConfirmationRequired
	 *
	 * @param string $appId
	 * @return JSONResponse
	 */
	#[PasswordConfirmationRequired]
	public function force(string $appId): JSONResponse {
		$appId = OC_App::cleanAppId($appId);
		$this->appManager->ignoreNextcloudRequirementForApp($appId);
		return new JSONResponse();
	}

	/**
	 * Get all available categories
	 *
	 * @return JSONResponse
	 */
	public function listCategories(): JSONResponse {
		return new JSONResponse($this->getAllCategories());
	}

	/**
	 * Using default methods to fetch App Store categories as they are the same for ExApps
	 *
	 * @return mixed
	 */
	private function getAllCategories(): array {
		$currentLang = substr($this->l10nFactory->findLanguage(), 0, 2);
		$categories = $this->categoryFetcher->get();
		return array_map(function (array $category) use ($currentLang) {
			return [
				'id' => $category['id'],
				'ident' => $category['id'],
				'displayName' => $category['translations'][$currentLang]['name'] ?? $category['translations']['en']['name']
			];
		}, $categories);
	}

	private function sortApps($a, $b): int {
		$a = (string)$a['name'];
		$b = (string)$b['name'];
		if ($a === $b) {
			return 0;
		}
		return ($a < $b) ? -1 : 1;
	}
}
