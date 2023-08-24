<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppMapper;

use OCA\AppEcosystemV2\Notifications\ExNotificationsManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Log\ILogFactory;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class AppEcosystemV2Service {
	public const BASIC_API_SCOPE = 1;
	public const MAX_SIGN_TIME_X_MIN_DIFF = 60 * 5;
	public const CACHE_TTL = 60 * 60; // 1 hour

	private LoggerInterface $logger;
	private ILogFactory $logFactory;
	private ICache $cache;
	private IThrottler $throttler;
	private IConfig $config;
	private IClient $client;
	private ExAppMapper $exAppMapper;
	private IAppManager $appManager;
	private ISecureRandom $random;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private ExAppApiScopeService $exAppApiScopeService;
	private ExAppUsersService $exAppUsersService;
	private ExAppScopesService $exAppScopesService;
	private ExAppConfigService $exAppConfigService;
	private ExNotificationsManager $exNotificationsManager;

	public function __construct(
		LoggerInterface $logger,
		ILogFactory $logFactory,
		ICacheFactory $cacheFactory,
		IThrottler $throttler,
		IConfig $config,
		IClientService $clientService,
		ExAppMapper $exAppMapper,
		IAppManager $appManager,
		ExAppUsersService $exAppUserService,
		ExAppApiScopeService $exAppApiScopeService,
		ExAppScopesService $exAppScopesService,
		ISecureRandom $random,
		IUserSession $userSession,
		IUserManager $userManager,
		ExAppConfigService $exAppConfigService,
		ExNotificationsManager $exNotificationsManager,
	) {
		$this->logger = $logger;
		$this->logFactory = $logFactory;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/service');
		$this->throttler = $throttler;
		$this->config = $config;
		$this->client = $clientService->newClient();
		$this->exAppMapper = $exAppMapper;
		$this->appManager = $appManager;
		$this->random = $random;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->exAppUsersService = $exAppUserService;
		$this->exAppApiScopeService = $exAppApiScopeService;
		$this->exAppScopesService = $exAppScopesService;
		$this->exAppConfigService = $exAppConfigService;
		$this->exNotificationsManager = $exNotificationsManager;
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
			'status' => json_encode(['active' => true]), // TODO: Add status request to ExApp
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
			$this->cache->remove('/exApp_' . $appId);
			// TODO: Do we need to remove ExApp container
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

				$exAppEnabled = $this->requestToExApp(null, null, $exApp, '/enabled?enabled=1', 'PUT');
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
			$exAppDisabled = $this->requestToExApp(null, null, $exApp, '/enabled?enabled=0', 'PUT');
			if ($exAppDisabled instanceof IResponse) {
				$response = json_decode($exAppDisabled->getBody(), true);
				if (isset($response['error']) && strlen($response['error']) !== 0) {
					$this->logger->error(sprintf('Failed to disable ExApp %s. Error: %s', $exApp->getAppid(), $response['error']));
				}
			} elseif (isset($exAppDisabled['error'])) {
				$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $exAppDisabled['error']));
			}
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), false) !== 1) {
				return false;
			}
			$this->updateExAppLastCheckTime($exApp);
			$cacheKey = '/exApp_' . $exApp->getAppid();
			$exApp->setEnabled(0);
			$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
			return true;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while disabling ExApp: %s', $e->getMessage()));
			return false;
		}
	}

	/**
	 * Update ExApp info (version and name changes after update)
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

		$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
		return true;
	}

	/**
	 * Send status check request to ExApp
	 *
	 * @param string $appId
	 *
	 * @return array|null
	 */
	public function getAppStatus(string $appId): ?array {
		$exApp = $this->getExApp($appId);
		if ($exApp === null) {
			return null;
		}

		$response = $this->requestToExApp(null, '', $exApp, '/status', 'GET');
		if ($response instanceof IResponse && $response->getStatusCode() === 200) {
			$status = json_decode($response->getBody(), true);
			$exApp->setStatus($status);
			$this->updateExAppLastCheckTime($exApp);
		}
		return json_decode($exApp->getStatus(), true);
	}

	public function heartbeatExApp(array $params): bool {
		$heartbeatAttempts = 0;
		$delay = 1;
		$maxHeartbeatAttempts = (60 * 60) / $delay; // 60 * 60 / delay = minutes for container initialization
		$heartbeatUrl = $this->getExAppUrl(
			$params['protocol'],
			$params['host'],
			(int) $params['port'],
		) . '/heartbeat';

		while ($heartbeatAttempts < $maxHeartbeatAttempts) {
			$heartbeatAttempts++;
			$ch = curl_init($heartbeatUrl);
			$headers = [
				'Accept: application/json',
				'Content-Type: application/json',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			$heartbeatResult = curl_exec($ch);
			$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($statusCode === 200) {
				$result = json_decode($heartbeatResult, true);
				if (isset($result['status']) && $result['status'] === 'ok') {
					return true;
				}
			}
			sleep($delay);
		}

		return false;
	}

	public function getExAppRequestedScopes(ExApp $exApp, ?\SimpleXMLElement $infoXml = null, array $jsonInfo = []): ?array {
		// TODO: Add download of info.xml from AppStore if not passed

		if (isset($infoXml)) {
			$scopes = $infoXml->xpath('ex-app/scopes');
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
		} elseif (isset($jsonInfo['scopes'])) {
			return $jsonInfo['scopes'];
		}

		return ['error' => 'Failed to get ExApp requested scopes.'];
	}

	/**
	 * Request to ExApp with AppEcosystem auth headers and ExApp user initialization
	 *
	 * @param IRequest|null $request
	 * @param string $userId
	 * @param ExApp $exApp
	 * @param string $route
	 * @param string $method
	 * @param array $params
	 *
	 * @return array|IResponse
	 */
	public function aeRequestToExApp(
		?IRequest $request,
		string $userId,
		ExApp $exApp,
		string $route,
		string $method = 'POST',
		array $params = []
	): array|IResponse {
		try {
			$this->exAppUsersService->setupExAppUser($exApp, $userId);
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error while inserting ExApp %s user. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return ['error' => 'Error while inserting ExApp user: ' . $e->getMessage()];
		}
		return $this->requestToExApp($request, $userId, $exApp, $route, $method, $params);
	}

	/**
	 * Request to ExApp with AppEcosystem auth headers
	 *
	 * @param IRequest|null $request
	 * @param string|null $userId
	 * @param ExApp $exApp
	 * @param string $route
	 * @param string $method
	 * @param array $params
	 *
	 * @return array|IResponse
	 */
	public function requestToExApp(
		?IRequest $request,
		?string $userId,
		ExApp $exApp,
		string $route,
		string $method = 'POST',
		array $params = []
	): array|IResponse {
		$this->handleExAppDebug($exApp, $request, true);
		try {
			$url = $this->getExAppUrl(
				$exApp->getProtocol(),
				$exApp->getHost(),
				$exApp->getPort()) . $route;

			$options = [
				'headers' => [
					'AE-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
					'EX-APP-ID' => $exApp->getAppid(),
					'EX-APP-VERSION' => $exApp->getVersion(),
					'NC-USER-ID' => $userId,
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$url .= '?' . $this->getUriEncodedParams($params);
				} else {
					$options['json'] = $params;
				}
			}

			$options['headers']['AE-DATA-HASH'] = '';
			$options['headers']['AE-SIGN-TIME'] = strval(time());
			[$signature, $dataHash] = $this->generateRequestSignature($method, $route, $options, $exApp->getSecret(), $params);
			$options['headers']['AE-SIGNATURE'] = $signature;
			$options['headers']['AE-DATA-HASH'] = $dataHash;
			$options['headers']['AE-REQUEST-ID'] = $request instanceof IRequest ? $request->getId() : 'CLI';

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
			//			TODO: Add files support
			return $response;
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error during request to ExApp %s: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $protocol
	 * @param string $host
	 * @param int $port
	 *
	 * @return string
	 */
	public function getExAppUrl(string $protocol, string $host, int $port): string {
		return sprintf('%s://%s:%s', $protocol, $host, $port);
	}

	public function buildExAppHost(array $deployConfig): string {
		if ((isset($deployConfig['net']) && $deployConfig['net'] !== 'host') || isset($deployConfig['host'])) {
			return '0.0.0.0';
		}
		return '127.0.0.1';
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
	 * Generates request signature and data hash.
	 * Data hash computed from request body even if it's empty.
	 *
	 * @param string $method
	 * @param string $uri
	 * @param array $options
	 * @param string $secret
	 * @param array $params
	 *
	 * @return array
	 */
	public function generateRequestSignature(string $method, string $uri, array $options, string $secret, array $params = []): array {
		$headers = [];
		if (isset($options['headers']['AE-VERSION'])) {
			$headers['AE-VERSION'] = $options['headers']['AE-VERSION'];
		}
		if (isset($options['headers']['EX-APP-ID'])) {
			$headers['EX-APP-ID'] = $options['headers']['EX-APP-ID'];
		}
		if (isset($options['headers']['EX-APP-VERSION'])) {
			$headers['EX-APP-VERSION'] = $options['headers']['EX-APP-VERSION'];
		}
		if (isset($options['headers']['NC-USER-ID']) && $options['headers']['NC-USER-ID'] !== '') {
			$headers['NC-USER-ID'] = $options['headers']['NC-USER-ID'];
		}

		if ($method === 'GET') {
			if (!empty($params)) {
				$queryParams = $this->getUriEncodedParams($params);
				$uri .= '?' . $queryParams;
			}
			$dataParams = '';
		} else {
			$dataParams = isset($options['json']) ? json_encode($options['json']) : '';
		}

		$dataHash = $this->generateDataHash($dataParams);
		if (isset($options['headers']['AE-DATA-HASH'])) {
			$headers['AE-DATA-HASH'] = $dataHash;
		}
		if (isset($options['headers']['AE-SIGN-TIME'])) {
			$headers['AE-SIGN-TIME'] = $options['headers']['AE-SIGN-TIME'];
		}
		$body = $method . $uri . json_encode($headers, JSON_UNESCAPED_SLASHES);
		return [hash_hmac('sha256', $body, $secret), $dataHash];
	}

	private function generateDataHash(string $data): string {
		$hashContext = hash_init('xxh64');
		hash_update($hashContext, $data);
		return hash_final($hashContext);
	}

	/**
	 * AppEcosystem authentication request validation for Nextcloud:
	 *  - checks if ExApp exists and is enabled
	 *  - checks if ExApp version changed and updates it in database
	 *  - validates request sign time (if it's complies with set time window)
	 *  - builds and checks request signature
	 *  - checks if request data hash is valid
	 *  - checks ExApp scopes <-> ExApp API copes
	 *
	 * More info in docs: https://cloud-py-api.github.io/app_ecosystem_v2/authentication.html
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
				'userid' => $request->getHeader('NC-USER-ID'),
			]);
			return false;
		}

		$this->handleExAppDebug($exApp, $request, false);

		$headers = [
			'AE-VERSION' => $request->getHeader('AE-VERSION'),
			'EX-APP-ID' => $request->getHeader('EX-APP-ID'),
			'EX-APP-VERSION' => $request->getHeader('EX-APP-VERSION'),
		];
		$userId = $request->getHeader('NC-USER-ID');
		if ($userId !== '') {
			$headers['NC-USER-ID'] = $userId;
		}
		$requestSignature = $request->getHeader('AE-SIGNATURE');
		$dataHash = $request->getHeader('AE-DATA-HASH');
		$headers['AE-DATA-HASH'] = $dataHash;
		$signTime = $request->getHeader('AE-SIGN-TIME');
		if (!$this->verifySignTime($signTime)) {
			$this->logger->error(sprintf('Sign time %s is not valid', $signTime));
			return false;
		}
		$headers['AE-SIGN-TIME'] = $signTime;

		$body = $request->getMethod() . $request->getRequestUri() . json_encode($headers, JSON_UNESCAPED_SLASHES);
		$signature = hash_hmac('sha256', $body, $exApp->getSecret());
		$signatureValid = $signature === $requestSignature;

		if ($signatureValid) {
			if (!$exApp->getEnabled()) {
				$this->logger->error(sprintf('ExApp with appId %s is disabled (%s)', $request->getHeader('EX-APP-ID'), $exApp->getEnabled()));
				return false;
			}
			if (!$this->handleExAppVersionChange($request, $exApp)) {
				return false;
			}
			if (!$this->verifyDataHash($dataHash)) {
				$this->logger->error(sprintf('Data hash %s is not valid', $dataHash));
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
				'userid' => $request->getHeader('NC-USER-ID'),
			]);
		}

		$this->logger->error(sprintf('ExApp %s request to NC validation failed.', $exApp->getAppid()));
		return false;
	}

	/**
	 * Final step of AppEcosystem authentication request validation for Nextcloud:
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
		$this->throttler->resetDelay($request->getRemoteAddress(), Application::APP_ID, [
			'appid' => $request->getHeader('EX-APP-ID'),
			'userid' => $userId,
		]);
		return true;
	}

	/**
	 * Verify if sign time is within MAX_SIGN_TIME_DIFF (5 min)
	 *
	 * @param string $signTime
	 * @return bool
	 */
	private function verifySignTime(string $signTime): bool {
		$signTime = intval($signTime);
		$currentTime = time();
		$diff = $currentTime - $signTime;
		if ($diff > self::MAX_SIGN_TIME_X_MIN_DIFF) {
			$this->logger->error(sprintf('AE-SIGN-TIME is too old. Diff: %s', $diff));
			return false;
		}
		if ($diff < 0) {
			$this->logger->error(sprintf('AE-SIGN-TIME diff is negative: %s', $diff));
			return false;
		}
		return true;
	}

	private function verifyDataHash(string $dataHash): bool {
		$hashContext = hash_init('xxh64');
		$stream = fopen('php://input', 'r');
		hash_update_stream($hashContext, $stream, -1);
		fclose($stream);
		$phpInputHash = hash_final($hashContext);
		return $dataHash === $phpInputHash;
	}

	public function updateExAppLastCheckTime(ExApp &$exApp): void {
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
	 * Ref: https://github.com/cloud-py-api/app_ecosystem_v2/pull/29
	 * TODO: Add link to docs with warning and mark as not-recommended
	 *
	 * @param IRequest $request
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function handleExAppVersionChange(IRequest $request, ExApp &$exApp): bool {
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
			'AE-VERSION',
			'EX-APP-VERSION',
			'AE-SIGN-TIME',
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
			$aeDebugLogger = $this->getCustomLogger('ae_debug.log');
			$aeDebugLogger->log($exAppDebugSettings['level'], $message, [
				'app' => $exApp->getAppid(),
				'request_info' => $request instanceof IRequest ? $this->buildRequestInfo($request) : 'CLI request',
			]);
		}
	}
}
