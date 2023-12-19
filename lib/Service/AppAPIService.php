<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppMapper;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Notifications\ExNotificationsManager;
use OCA\AppAPI\Service\UI\FilesActionsMenuService;
use OCA\AppAPI\Service\UI\InitialStateService;
use OCA\AppAPI\Service\UI\ScriptsService;
use OCA\AppAPI\Service\UI\StylesService;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Log\ILogFactory;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

class AppAPIService {
	public const BASIC_API_SCOPE = 1;
	public const CACHE_TTL = 60 * 60; // 1 hour

	private ICache $cache;
	private IClient $client;

	public function __construct(
		private readonly LoggerInterface          $logger,
		private readonly ILogFactory              $logFactory,
		ICacheFactory                              $cacheFactory,
		private readonly IThrottler              $throttler,
		private readonly IConfig                 $config,
		IClientService                           $clientService,
		private readonly ExAppMapper             $exAppMapper,
		private readonly IAppManager             $appManager,
		private readonly ExAppUsersService       $exAppUsersService,
		private readonly ExAppApiScopeService    $exAppApiScopeService,
		private readonly ExAppScopesService      $exAppScopesService,
		private readonly TopMenuService          $topMenuService,
		private readonly InitialStateService     $initialStateService,
		private readonly ScriptsService          $scriptsService,
		private readonly StylesService           $stylesService,
		private readonly FilesActionsMenuService $filesActionsMenuService,
		private readonly ISecureRandom           $random,
		private readonly IUserSession            $userSession,
		private readonly ISession                $session,
		private readonly IUserManager            $userManager,
		private readonly ExAppConfigService      $exAppConfigService,
		private readonly ExNotificationsManager  $exNotificationsManager,
		private readonly TalkBotsService         $talkBotsService,
		private readonly ExAppFetcher            $exAppFetcher,
		private readonly ExAppArchiveFetcher     $exAppArchiveFetcher,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/service');
		$this->client = $clientService->newClient();
	}

	public function getExApp(string $appId): ?ExApp {
		try {
			$cacheKey = '/exApp_' . $appId;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached instanceof ExApp ? $cached : new ExApp($cached);
			}

			$exApp = $this->exAppMapper->findByAppId($appId);
			$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
			return $exApp;
		} catch (DoesNotExistException) {
		} catch (MultipleObjectsReturnedException|Exception $e) {
			$this->logger->debug(sprintf('Failed to get ExApp %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Register ExApp or update if already exists
	 *
	 * @param string $appId
	 * @param array $appData [version, name, daemon_config_id, protocol, host, port, secret]
	 *
	 * @return ExApp|null
	 */
	public function registerExApp(string $appId, array $appData): ?ExApp {
		$exApp = new ExApp([
			'appid' => $appId,
			'version' => $appData['version'],
			'name' => $appData['name'],
			'daemon_config_name' => $appData['daemon_config_name'],
			'protocol' => $appData['protocol'],
			'host' => $appData['host'],
			'port' => $appData['port'],
			'secret' => $appData['secret'] !== '' ? $appData['secret'] : $this->random->generate(128),
			'status' => json_encode(['active' => false, 'progress' => 0]),
			'created_time' => time(),
			'last_check_time' => time(),
		]);
		try {
			$cacheKey = '/exApp_' . $appId;
			$exApp = $this->exAppMapper->insert($exApp);
			$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
			return $exApp;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while registering ExApp %s: %s', $appId, $e->getMessage()));
			return null;
		}
	}

	/**
	 * Unregister ExApp.
	 * Removes ExApp from database and cache.
	 *
	 * @param string $appId
	 *
	 * @return ExApp|null
	 */
	public function unregisterExApp(string $appId): ?ExApp {
		$exApp = $this->getExApp($appId);
		if ($exApp === null) {
			return null;
		}
		try {
			if ($this->exAppMapper->deleteExApp($exApp) !== 1) {
				$this->logger->error(sprintf('Error while unregistering ExApp: %s', $appId));
				return null;
			}
			// TODO: Do we need to remove app_config_ex, app_preferences_ex too
			$this->exAppScopesService->removeExAppScopes($exApp);
			$this->exAppUsersService->removeExAppUsers($exApp);
			$this->talkBotsService->unregisterExAppTalkBots($exApp); // TODO: Think about internal Events for clean and flexible unregister ExApp callbacks
			$this->filesActionsMenuService->unregisterExAppFileActions($appId);
			$this->topMenuService->unregisterExAppMenuEntries($appId);
			$this->initialStateService->deleteExAppInitialStates($appId);
			$this->scriptsService->deleteExAppScripts($appId);
			$this->stylesService->deleteExAppStyles($appId);
			$this->cache->remove('/exApp_' . $appId);
			return $exApp;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while unregistering ExApp: %s', $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	public function getExAppsByPort(int $port): array {
		try {
			return $this->exAppMapper->findByPort($port);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppRandomPort(): int {
		$port = 10000 + (int) $this->random->generate(4, ISecureRandom::CHAR_DIGITS);
		while ($this->getExAppsByPort($port) !== []) {
			$port = 10000 + (int) $this->random->generate(4, ISecureRandom::CHAR_DIGITS);
		}
		return $port;
	}

	/**
	 * Enable ExApp. Sends request to ExApp to update enabled state.
	 * If request fails, ExApp will be disabled.
	 * Removes ExApp from cache.
	 *
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function enableExApp(ExApp $exApp): bool {
		try {
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), true) === 1) {
				$cacheKey = '/exApp_' . $exApp->getAppid();
				$exApp->setEnabled(1);
				$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
				$this->filesActionsMenuService->resetCacheEnabled();
				$this->topMenuService->resetCacheEnabled();

				$exAppEnabled = $this->requestToExApp($exApp, '/enabled?enabled=1', null, 'PUT');
				if ($exAppEnabled instanceof IResponse) {
					$response = json_decode($exAppEnabled->getBody(), true);
					if (isset($response['error']) && strlen($response['error']) === 0) {
						$this->updateExAppLastCheckTime($exApp);
					} else {
						$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $response['error']));
						$this->disableExApp($exApp);
						return false;
					}
				} elseif (isset($exAppEnabled['error'])) {
					$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $exAppEnabled['error']));
					$this->disableExApp($exApp);
					return false;
				}

				return true;
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while enabling ExApp: %s', $e->getMessage()));
			return false;
		}
		return false;
	}

	/**
	 * Disable ExApp. Sends request to ExApp to update enabled state.
	 * If request fails, ExApp keep disabled in database.
	 * Removes ExApp from cache.
	 *
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function disableExApp(ExApp $exApp): bool {
		try {
			$exAppDisabled = $this->requestToExApp($exApp, '/enabled?enabled=0', null, 'PUT');
			if ($exAppDisabled instanceof IResponse) {
				$response = json_decode($exAppDisabled->getBody(), true);
				if (isset($response['error']) && strlen($response['error']) !== 0) {
					$this->logger->error(sprintf('Failed to disable ExApp %s. Error: %s', $exApp->getAppid(), $response['error']));
				}
			} elseif (isset($exAppDisabled['error'])) {
				$this->logger->error(sprintf('Failed to disable ExApp %s. Error: %s', $exApp->getAppid(), $exAppDisabled['error']));
			}
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), false) !== 1) {
				$this->logger->error(sprintf('Error updating state of ExApp %s.', $exApp->getAppid()));
				return false;
			}
			$this->updateExAppLastCheckTime($exApp);
			$cacheKey = '/exApp_' . $exApp->getAppid();
			$exApp->setEnabled(0);
			$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
			$this->topMenuService->resetCacheEnabled();
			$this->filesActionsMenuService->resetCacheEnabled();
			return true;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while disabling ExApp: %s', $e->getMessage()));
			return false;
		}
	}

	/**
	 * Update ExApp info (version, name, system app flag changes after update)
	 *
	 * @param ExApp $exApp
	 * @param array $exAppInfo
	 *
	 * @return bool
	 */
	public function updateExAppInfo(ExApp $exApp, array $exAppInfo): bool {
		$cacheKey = '/exApp_' . $exApp->getAppid();

		$exApp->setVersion($exAppInfo['version']);
		if (!$this->updateExAppVersion($exApp)) {
			return false;
		}
		$exApp->setName($exAppInfo['name']);
		if (!$this->updateExAppName($exApp)) {
			return false;
		}

		// Update system app flag
		$isSystemApp = $this->exAppUsersService->exAppUserExists($exApp->getAppid(), '');
		if (filter_var($exAppInfo['system_app'], FILTER_VALIDATE_BOOLEAN) && !$isSystemApp) {
			$this->exAppUsersService->setupSystemAppFlag($exApp);
		} else {
			$this->exAppUsersService->removeExAppUser($exApp, '');
		}

		$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
		return true;
	}

	/**
	 * Update ExApp status during initialization step.
	 * Active status is set when progress reached 100%.
	 *
	 * @param string $appId
	 * @param int $progress
	 * @param string $error
	 * @param bool $update
	 * @param bool $init
	 *
	 * @return void
	 */
	public function setAppInitProgress(string $appId, int $progress, string $error = '', bool $update = false, bool $init = false): void {
		$exApp = $this->getExApp($appId);
		$cacheKey = '/exApp_' . $exApp->getAppid();

		$status = json_decode($exApp->getStatus(), true);

		if ($init) {
			$status['init_start_time'] = time();
		}

		if ($update) {
			// Set active=false during update action, for register it already false
			$status['active'] = false;
		}

		if ($status['active']) {
			return;
		}

		if ($error !== '') {
			$this->logger->error(sprintf('ExApp %s initialization failed. Error: %s', $appId, $error));
			$status['error'] = $error;
			unset($status['progress']);
			unset($status['init_start_time']);
		} else {
			if ($progress >= 0 && $progress < 100) {
				$status['progress'] = $progress;
			} elseif ($progress === 100) {
				unset($status['progress']);
			} else {
				throw new \InvalidArgumentException('Invalid ExApp status progress value');
			}
			$status['active'] = $progress === 100;
		}
		$exApp->setStatus(json_encode($status));

		try {
			$exApp = $this->exAppMapper->update($exApp);
			$this->updateExAppLastCheckTime($exApp);
			$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
			if ($progress === 100) {
				$this->enableExApp($exApp);
			}
		} catch (Exception) {
		}
	}

	/**
	 * Regular ExApp heartbeat to verify connection
	 *
	 * @param array $params ExApp url params (protocol, host, port)
	 *
	 * @return bool
	 */
	public function heartbeatExApp(array $params): bool {
		$heartbeatAttempts = 0;
		$delay = 1;
		$maxHeartbeatAttempts = 60 * 10 * $delay; // minutes for container initialization
		$heartbeatUrl = self::getExAppUrl(
			$params['protocol'],
			$params['host'],
			(int) $params['port'],
		) . '/heartbeat';

		$options = [
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'nextcloud' => [
				'allow_local_address' => true,
			],
		];

		while ($heartbeatAttempts < $maxHeartbeatAttempts) {
			$heartbeatAttempts++;
			try {
				$heartbeatResult = $this->client->get($heartbeatUrl, $options);
			} catch (\Exception) {
				sleep($delay);
				continue;
			}
			$statusCode = $heartbeatResult->getStatusCode();
			if ($statusCode === 200) {
				$result = json_decode($heartbeatResult->getBody(), true);
				if (isset($result['status']) && $result['status'] === 'ok') {
					return true;
				}
			}
			sleep($delay);
		}

		return false;
	}

	/**
	 * Dispatch ExApp initialization step, that may take a long time to display the progress of initialization.
	 *
	 * @param ExApp $exApp
	 * @return bool
	 */
	public function dispatchExAppInit(ExApp $exApp, bool $update = false): bool {
		$this->setAppInitProgress($exApp->getAppid(), 0, '', $update, true);
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$args = ['app_api:app:dispatch_init', $exApp->getAppid()];
		$args = array_map(function ($arg) {
			return escapeshellarg($arg);
		}, $args);
		$args[] = '--no-ansi --no-warnings';
		$args = implode(' ', $args);
		$occDirectory = dirname(__FILE__, 5);
		$process = proc_open('php console.php ' . $args, $descriptors, $pipes, $occDirectory);
		if (!is_resource($process)) {
			return false;
		}
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return true;
	}

	public function dispatchExAppInitInternal(ExApp $exApp): void {
		// start in background in a separate process
		$initUrl = self::getExAppUrl(
			$exApp->getProtocol(),
			$exApp->getHost(),
			$exApp->getPort(),
		) . '/init';

		$options = [
			'headers' => $this->buildAppAPIAuthHeaders(null, null, $exApp),
			'nextcloud' => [
				'allow_local_address' => true,
			],
		];

		try {
			$this->client->post($initUrl, $options);
		} catch (\Exception $e) {
			$statusCode = $e->getCode();
			if (($statusCode === Http::STATUS_NOT_IMPLEMENTED) || ($statusCode === Http::STATUS_NOT_FOUND)) {
				$this->setAppInitProgress($exApp->getAppid(), 100);
			} else {
				$this->setAppInitProgress($exApp->getAppid(), 0, $e->getMessage());
			}
		}
	}

	public function getExAppRequestedScopes(ExApp $exApp, ?SimpleXMLElement $infoXml = null, array $jsonInfo = []): ?array {
		if (isset($jsonInfo['scopes'])) {
			return $jsonInfo['scopes'];
		}

		if ($infoXml === null) {
			$exAppInfo = $this->getExAppInfoFromAppstore($exApp);
			if (isset($exAppInfo)) {
				$infoXml = $exAppInfo;
			}
		}

		if (isset($infoXml)) {
			$scopes = $infoXml->xpath('external-app/scopes');
			if ($scopes !== false) {
				$scopes = (array) $scopes[0];
				$required = array_map(function (string $scopeGroup) {
					return $scopeGroup;
				}, (array) $scopes['required']->value);
				$optional = array_map(function (string $scopeGroup) {
					return $scopeGroup;
				}, (array) $scopes['optional']->value);
				return [
					'required' => array_values($required),
					'optional' => array_values($optional),
				];
			}
		}

		return ['error' => 'Failed to get ExApp requested scopes.'];
	}

	/**
	 * Get info from App Store releases for specific ExApp and its current version
	 *
	 * @param ExApp $exApp
	 *
	 * @return SimpleXMLElement|null
	 */
	public function getExAppInfoFromAppstore(ExApp $exApp): ?SimpleXMLElement {
		$exApps = $this->exAppFetcher->get();
		$exAppAppstoreData = array_filter($exApps, function (array $exAppItem) use ($exApp) {
			return $exAppItem['id'] === $exApp->getAppid() && count(array_filter($exAppItem['releases'], function (array $release) use ($exApp) {
				return $release['version'] === $exApp->getVersion();
			})) === 1;
		});
		if (count($exAppAppstoreData) === 1) {
			return $this->exAppArchiveFetcher->downloadInfoXml($exAppAppstoreData);
		}
		return null;
	}

	/**
	 * Get latest ExApp release info by ExApp appid (in case of first installation or update)
	 *
	 * @param string $appId
	 *
	 * @return SimpleXMLElement|null
	 */
	public function getLatestExAppInfoFromAppstore(string $appId): ?SimpleXMLElement {
		$exApps = $this->exAppFetcher->get();
		$exAppAppstoreData = array_filter($exApps, function (array $exAppItem) use ($appId) {
			return $exAppItem['id'] === $appId && count($exAppItem['releases']) > 0;
		});
		$exAppAppstoreData = end($exAppAppstoreData);
		$exAppReleaseInfo = end($exAppAppstoreData['releases']);
		if ($exAppReleaseInfo !== false) {
			return $this->exAppArchiveFetcher->downloadInfoXml($exAppAppstoreData);
		}
		return null;
	}

	/**
	 * Request to ExApp with AppAPI auth headers and ExApp user initialization
	 *
	 * @param ExApp $exApp
	 * @param string $route
	 * @param string $userId
	 * @param string $method
	 * @param array $params
	 * @param array $options
	 * @param IRequest|null $request
	 *
	 * @return array|IResponse
	 */
	public function aeRequestToExApp(
		ExApp $exApp,
		string $route,
		string $userId,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IResponse {
		try {
			$this->exAppUsersService->setupExAppUser($exApp, $userId);
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error while inserting ExApp %s user. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return ['error' => 'Error while inserting ExApp user: ' . $e->getMessage()];
		}
		return $this->requestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Request to ExApp by appId with AppAPI auth headers and ExApp user initialization
	 *
	 * @param string $appId
	 * @param string $route
	 * @param string $userId
	 * @param string $method
	 * @param array $params
	 * @param array $options
	 * @param IRequest|null $request
	 *
	 * @return array|IResponse
	 */
	public function aeRequestToExAppById(
		string $appId,
		string $route,
		string $userId,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	):  array|IResponse {
		$exApp = $this->getExApp($appId);
		if ($exApp === null) {
			return ['error' => sprintf('ExApp `%s` not found', $appId)];
		}
		return $this->aeRequestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Request to ExApp by appId with AppAPI auth headers
	 *
	 * @param string $appId
	 * @param string $route
	 * @param string|null $userId
	 * @param string $method
	 * @param array $params
	 * @param array $options
	 * @param IRequest|null $request
	 *
	 * @return array|IResponse
	 */
	public function requestToExAppById(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	):  array|IResponse {
		$exApp = $this->getExApp($appId);
		if ($exApp === null) {
			return ['error' => sprintf('ExApp `%s` not found', $appId)];
		}
		return $this->requestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Request to ExApp with AppAPI auth headers
	 *
	 * @param ExApp $exApp
	 * @param string $route
	 * @param string|null $userId
	 * @param string $method
	 * @param array $params
	 * @param array $options
	 * @param IRequest|null $request
	 *
	 * @return array|IResponse
	 */
	public function requestToExApp(
		ExApp $exApp,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IResponse {
		$this->handleExAppDebug($exApp, $request, true);
		try {
			$url = self::getExAppUrl(
				$exApp->getProtocol(),
				$exApp->getHost(),
				$exApp->getPort()) . $route;

			if (isset($options['headers']) && is_array($options['headers'])) {
				$options['headers'] = [...$options['headers'], ...$this->buildAppAPIAuthHeaders($request, $userId, $exApp)];
			} else {
				$options['headers'] = $this->buildAppAPIAuthHeaders($request, $userId, $exApp);
			}
			$options['nextcloud'] = [
				'allow_local_address' => true, // it's required as we are using ExApp appid as hostname (usually local)
			];

			$isMultipart = false;
			$multipartData = [];
			if ($method === 'POST' || $method === 'PUT') {
				foreach ($params as $key => $value) {
					if (is_a($value, 'CURLStringFile')) {
						$isMultipart = true;
						$multipartData[] = [
							'name' => $key,
							'contents' => $value->data,
							'filename' => $value->postname,
							'headers' => ['Content-Type' => $value->mime]
						];
					} else {
						$multipartData[] = [
							'name' => $key,
							'contents' => $value
						];
					}
				}
				if ($isMultipart) {
					$options['multipart'] = $multipartData;
				}
			}

			if ((!array_key_exists('multipart', $options)) && (count($params)) > 0) {
				if ($method === 'GET') {
					$url .= '?' . $this->getUriEncodedParams($params);
				} else {
					$options['json'] = $params;
				}
			}

			switch ($method) {
				case 'GET':
					$response = $this->client->get($url, $options);
					break;
				case 'POST':
					$response = $this->client->post($url, $options);
					break;
				case 'PUT':
					$response = $this->client->put($url, $options);
					break;
				case 'DELETE':
					$response = $this->client->delete($url, $options);
					break;
				default:
					return ['error' => 'Bad HTTP method'];
			}
			return $response;
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error during request to ExApp %s: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}

	private function buildAppAPIAuthHeaders(?IRequest $request, ?string $userId, ExApp $exApp): array {
		return [
			'AA-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
			'EX-APP-ID' => $exApp->getAppid(),
			'EX-APP-VERSION' => $exApp->getVersion(),
			'AUTHORIZATION-APP-API' => base64_encode($userId . ':' . $exApp->getSecret()),
			'AA-REQUEST-ID' => $request instanceof IRequest ? $request->getId() : 'CLI',
		];
	}

	/**
	 * @param string $protocol
	 * @param string $host
	 * @param int $port
	 *
	 * @return string
	 */
	public static function getExAppUrl(string $protocol, string $host, int $port): string {
		return sprintf('%s://%s:%s', $protocol, $host, $port);
	}

	public function isAppHostNameLocal(string $hostname): bool {
		return $hostname === '127.0.0.1' || $hostname === 'localhost' || $hostname === '::1';
	}

	public function buildExAppHost(array $deployConfig): string {
		if (isset($deployConfig['net'])) {
			if (($deployConfig['net'] === 'host') &&
				(isset($deployConfig['host']) && $this->isAppHostNameLocal($deployConfig['host']))
			) {
				return '127.0.0.1';  # ExApp using host network, it is visible for Nextcloud on loop-back adapter
			}
			return '0.0.0.0';
		}
		return '127.0.0.1';  # fallback to loop-back adapter
	}

	private function getUriEncodedParams(array $params): string {
		$paramsContent = '';
		foreach ($params as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $oneArrayValue) {
					$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
				}
				unset($params[$key]);
			}
		}
		return $paramsContent . http_build_query($params);
	}

	/**
	 * AppAPI authentication request validation for Nextcloud:
	 *  - checks if ExApp exists and is enabled
	 *  - checks if ExApp version changed and updates it in database
	 *  - checks if ExApp shared secret valid
	 *  - checks ExApp scopes <-> ExApp API copes
	 *
	 * More info in docs: https://cloud-py-api.github.io/app_api/authentication.html
	 *
	 * @param IRequest $request
	 * @param bool $isDav
	 *
	 * @return bool
	 */
	public function validateExAppRequestToNC(IRequest $request, bool $isDav = false): bool {
		$this->throttler->sleepDelayOrThrowOnMax($request->getRemoteAddress(), Application::APP_ID);

		$exApp = $this->getExApp($request->getHeader('EX-APP-ID'));
		if ($exApp === null) {
			$this->logger->error(sprintf('ExApp with appId %s not found.', $request->getHeader('EX-APP-ID')));
			// Protection for guessing installed ExApps list
			$this->throttler->registerAttempt(Application::APP_ID, $request->getRemoteAddress(), [
				'appid' => $request->getHeader('EX-APP-ID'),
				'userid' => explode(':', base64_decode($request->getHeader('AUTHORIZATION-APP-API')), 2)[0],
			]);
			return false;
		}

		$this->handleExAppDebug($exApp, $request, false);

		$authorization = base64_decode($request->getHeader('AUTHORIZATION-APP-API'));
		if ($authorization === false) {
			$this->logger->error('Failed to parse AUTHORIZATION-APP-API');
			return false;
		}
		$userId = explode(':', $authorization, 2)[0];
		$authorizationSecret = explode(':', $authorization, 2)[1];
		$authValid = $authorizationSecret === $exApp->getSecret();

		if ($authValid) {
			if (!$exApp->getEnabled()) {
				// If ExApp is in initializing state, it is disabled yet, so we allow requests in such case
				if (!isset(json_decode($exApp->getStatus(), true)['progress'])) {
					$this->logger->error(sprintf('ExApp with appId %s is disabled (%s)', $request->getHeader('EX-APP-ID'), $request->getRequestUri()));
					return false;
				}
			}
			if (!$this->handleExAppVersionChange($request, $exApp)) {
				return false;
			}
			if (!$isDav) {
				try {
					$path = $request->getPathInfo();
				} catch (\Exception $e) {
					$this->logger->error(sprintf('Error getting path info. Error: %s', $e->getMessage()), ['exception' => $e]);
					return false;
				}
			} else {
				$path = '/dav/';
			}
			$apiScope = $this->exAppApiScopeService->getApiScopeByRoute($path);

			if ($apiScope === null) {
				$this->logger->error(sprintf('Failed to check apiScope %s', $path));
				return false;
			}
			// BASIC ApiScope is granted to all ExApps (all Api routes with BASIC scope group).
			if ($apiScope->getScopeGroup() !== self::BASIC_API_SCOPE) {
				if (!$this->exAppScopesService->passesScopeCheck($exApp, $apiScope->getScopeGroup())) {
					$this->logger->error(sprintf('ExApp %s not passed scope group check %s', $exApp->getAppid(), $path));
					return false;
				}
			}
			// For APIs that not assuming work under user context we do not check ExApp users
			if ($apiScope->getUserCheck()) {
				try {
					if (!$this->exAppUsersService->exAppUserExists($exApp->getAppid(), $userId)) {
						$this->logger->error(sprintf('ExApp %s user %s does not exist', $exApp->getAppid(), $userId));
						return false;
					}
				} catch (Exception $e) {
					$this->logger->error(sprintf('Failed to get ExApp %s user %s. Error: %s', $exApp->getAppid(), $userId, $e->getMessage()), ['exception' => $e]);
					return false;
				}
			}
			return $this->finalizeRequestToNC($userId, $request);
		} else {
			$this->logger->error(sprintf('Invalid signature for ExApp: %s and user: %s.', $exApp->getAppid(), $userId !== '' ? $userId : 'null'));
			$this->throttler->registerAttempt(Application::APP_ID, $request->getRemoteAddress(), [
				'appid' => $request->getHeader('EX-APP-ID'),
				'userid' => $userId,
			]);
		}

		$this->logger->error(sprintf('ExApp %s request to NC validation failed.', $exApp->getAppid()));
		return false;
	}

	/**
	 * Final step of AppAPI authentication request validation for Nextcloud:
	 *  - sets active user (null if not a user context)
	 *  - updates ExApp last response time
	 *
	 * @param string $userId
	 * @param IRequest $request
	 *
	 * @return bool
	 */
	private function finalizeRequestToNC(string $userId, IRequest $request): bool {
		if ($userId !== '') {
			$activeUser = $this->userManager->get($userId);
			if ($activeUser === null) {
				$this->logger->error(sprintf('Requested user does not exists: %s', $userId));
				return false;
			}
			$this->userSession->setUser($activeUser);
		} else {
			$this->userSession->setUser(null);
		}
		$this->session->set('app_api', true);
		$this->throttler->resetDelay($request->getRemoteAddress(), Application::APP_ID, [
			'appid' => $request->getHeader('EX-APP-ID'),
			'userid' => $userId,
		]);
		return true;
	}

	public function updateExAppLastCheckTime(ExApp $exApp): void {
		$exApp->setLastCheckTime(time());
		try {
			$this->exAppMapper->updateLastCheckTime($exApp);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while updating ExApp last check time for ExApp: %s. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
		}
	}

	public function updateExAppVersion(ExApp $exApp): bool {
		try {
			return $this->exAppMapper->updateExAppVersion($exApp) === 1;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to update ExApp %s version to %s', $exApp->getAppid(), $exApp->getVersion()), ['exception' => $e]);
			return false;
		}
	}

	public function updateExAppName(ExApp $exApp): bool {
		try {
			return $this->exAppMapper->updateExAppName($exApp) === 1;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to update ExApp %s name to %s', $exApp->getAppid(), $exApp->getName()), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Check if ExApp version changed and update it in database.
	 * Immediately disable ExApp and send notifications to the administrators (users of admins group).
	 * This handling only intentional case of manual ExApp update
	 * so the administrator must re-enable ExApp in UI or CLI after that.
	 *
	 * Ref: https://github.com/cloud-py-api/app_api/pull/29
	 * TODO: Add link to docs with warning and mark as not-recommended
	 *
	 * @param IRequest $request
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function handleExAppVersionChange(IRequest $request, ExApp $exApp): bool {
		$requestExAppVersion = $request->getHeader('EX-APP-VERSION');
		$versionValid = $exApp->getVersion() === $requestExAppVersion;
		if (!$versionValid) {
			// Update ExApp version
			$oldVersion = $exApp->getVersion();
			$exApp->setVersion($requestExAppVersion);
			if (!$this->updateExAppVersion($exApp)) {
				return false;
			}
			if ($this->disableExApp($exApp)) {
				$this->exNotificationsManager->sendAdminsNotification($exApp->getAppid(), [
					'object' => 'ex_app_update',
					'object_id' => $exApp->getAppid(),
					'subject_type' => 'ex_app_version_update',
					'subject_params' => [
						'rich_subject' => 'ExApp updated, action required!',
						'rich_subject_params' => [],
						'rich_message' => sprintf('ExApp %s disabled due to update from %s to %s. Manual re-enable required.', $exApp->getAppid(), $oldVersion, $exApp->getVersion()),
						'rich_message_params' => [],
					],
				]);
			}
			return false;
		}
		return true;
	}

	public function getExAppsList(string $list = 'enabled'): array {
		try {
			$exApps = $this->exAppMapper->findAll();

			if ($list === 'enabled') {
				$exApps = array_values(array_filter($exApps, function (ExApp $exApp) {
					return $exApp->getEnabled() === 1;
				}));
			}

			$exApps = array_map(function (ExApp $exApp) {
				return [
					'id' => $exApp->getAppid(),
					'name' => $exApp->getName(),
					'version' => $exApp->getVersion(),
					'enabled' => filter_var($exApp->getEnabled(), FILTER_VALIDATE_BOOLEAN),
					'last_check_time' => $exApp->getLastCheckTime(),
					'system' => $this->exAppUsersService->exAppUserExists($exApp->getAppid(), ''),
				];
			}, $exApps);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while getting ExApps list. Error: %s', $e->getMessage()), ['exception' => $e]);
			$exApps = [];
		}
		return $exApps;
	}

	public function getNCUsersList(): ?array {
		return array_map(function (IUser $user) {
			return $user->getUID();
		}, $this->userManager->searchDisplayName(''));
	}

	private function getCustomLogger(string $name): LoggerInterface {
		$path = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/' . $name;
		return $this->logFactory->getCustomPsrLogger($path);
	}

	private function buildRequestInfo(IRequest $request): array {
		$headers = [];
		$aeHeadersList = [
			'AA-VERSION',
			'EX-APP-VERSION',
		];
		foreach ($aeHeadersList as $header) {
			if ($request->getHeader($header) !== '') {
				$headers[$header] = $request->getHeader($header);
			}
		}
		return [
			'headers' => $headers,
			'params' => $request->getParams(),
		];
	}

	private function getExAppDebugSettings(ExApp $exApp): array {
		$exAppConfigs = $this->exAppConfigService->getAppConfigValues($exApp->getAppid(), ['debug', 'loglevel']);
		$debug = false;
		$level = $this->config->getSystemValue('loglevel', 2);
		foreach ($exAppConfigs as $exAppConfig) {
			if ($exAppConfig['configkey'] === 'debug') {
				$debug = $exAppConfig['configvalue'] === 1;
			}
			if ($exAppConfig['configkey'] === 'loglevel') {
				$level = intval($exAppConfig['configvalue']);
			}
		}
		return [
			'debug' => $debug,
			'level' => $level,
		];
	}

	private function handleExAppDebug(ExApp $exApp, ?IRequest $request, bool $fromNextcloud = true): void {
		$exAppDebugSettings = $this->getExAppDebugSettings($exApp);
		if ($exAppDebugSettings['debug']) {
			$message = $fromNextcloud
				? '[' . Application::APP_ID . '] Nextcloud --> ' . $exApp->getAppid()
				: '[' . Application::APP_ID . '] ' . $exApp->getAppid() . ' --> Nextcloud';
			$aeDebugLogger = $this->getCustomLogger('aa_debug.log');
			$aeDebugLogger->log($exAppDebugSettings['level'], $message, [
				'app' => $exApp->getAppid(),
				'request_info' => $request instanceof IRequest ? $this->buildRequestInfo($request) : 'CLI request',
			]);
		}
	}
}
