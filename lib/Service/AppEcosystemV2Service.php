<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExAppScope;
use OCA\AppEcosystemV2\Db\ExAppScopeMapper;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IUser;
use OCP\Log\ILogFactory;
use Psr\Log\LoggerInterface;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;

use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppApiScope;
use OCA\AppEcosystemV2\Db\ExAppApiScopeMapper;
use OCA\AppEcosystemV2\Db\ExAppMapper;
use OCA\AppEcosystemV2\Db\ExAppUser;
use OCA\AppEcosystemV2\Db\ExAppUserMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class AppEcosystemV2Service {
//	TODO: In addition to default constant scopes think about implementing scopes registration mechanism
	public const BASIC_API_SCOPE = 1;
	public const SYSTEM_API_SCOPE = 2;
	public const DAV_API_SCOPE = 3;
	const MAX_SIGN_TIME_DIFF = 60 * 5; // 5 min
	private LoggerInterface $logger;
	private ILogFactory $logFactory;
	private IConfig $config;
	private IClient $client;
	private ExAppMapper $exAppMapper;
	private IAppManager $appManager;
	private ExAppUserMapper $exAppUserMapper;
	private ISecureRandom $random;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private ExAppApiScopeMapper $exAppApiScopeMapper;
	private ExAppScopeMapper $exAppScopeMapper;
	private ExAppConfigService $exAppConfigService;

	public function __construct(
		LoggerInterface $logger,
		ILogFactory $logFactory,
		IConfig $config,
		IClientService $clientService,
		ExAppMapper $exAppMapper,
		IAppManager $appManager,
		ExAppUserMapper $exAppUserMapper,
		ExAppApiScopeMapper $exAppApiScopeMapper,
		ExAppScopeMapper $exAppScopeMapper,
		ISecureRandom $random,
		IUserSession $userSession,
		IUserManager $userManager,
		ExAppConfigService $exAppConfigService,
	) {
		$this->logger = $logger;
		$this->logFactory = $logFactory;
		$this->config = $config;
		$this->client = $clientService->newClient();
		$this->exAppMapper = $exAppMapper;
		$this->appManager = $appManager;
		$this->exAppUserMapper = $exAppUserMapper;
		$this->random = $random;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->exAppApiScopeMapper = $exAppApiScopeMapper;
		$this->exAppScopeMapper = $exAppScopeMapper;
		$this->exAppConfigService = $exAppConfigService;
	}

	public function getExApp(string $exAppId): ?ExApp {
		try {
			return $this->exAppMapper->findByAppId($exAppId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Register exApp
	 *
	 * @param string $appId
	 * @param array $appData [version, name, config]
	 *
	 * @return ExApp|null
	 */
	public function registerExApp(string $appId, array $appData): ?ExApp {
		try {
			$exApp = $this->exAppMapper->findByAppId($appId);
			$exApp->setVersion($appData['version']);
			$exApp->setName($appData['name']);
			$exApp->setConfig($appData['config']);
			$secret = $this->random->generate(128); // Temporal random secret
			$exApp->setSecret($secret);
			$exApp->setStatus(json_encode(['active' => true]));
			$exApp->setLastResponseTime(time());
			try {
				return $this->exAppMapper->update($exApp);
			} catch (\Exception $e) {
				$this->logger->error('Error while updating ex app: ' . $e->getMessage());
				return null;
			}
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exApp = new ExApp([
				'appid' => $appId,
				'version' => $appData['version'],
				'name' => $appData['name'],
				'config' => $appData['config'],
				'secret' =>  $this->random->generate(128),
				'status' => json_encode(['active' => true]),
				'created_time' => time(),
				'last_response_time' => time(),
			]);
			try {
				return $this->exAppMapper->insert($exApp);
			} catch (Exception $e) {
				$this->logger->error('Error while registering ex app: ' . $e->getMessage());
				return null;
			}
		}
	}

	/**
	 * Unregister ex app
	 *
	 * @param string $appId
	 *
	 * @return ExApp|null
	 */
	public function unregisterExApp(string $appId): ?ExApp {
		try {
			$exApp = $this->exAppMapper->findByAppId($appId);
			if ($this->exAppMapper->deleteExApp($exApp) !== 1) {
				$this->logger->error('Error while unregistering ex app: ' . $appId);
				return null;
			}
			return $exApp;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error('Error while unregistering ex app: ' . $e->getMessage());
			return null;
		}
	}

	public function getExAppScopeGroups(ExApp $exApp): array {
		try {
			return $this->exAppScopeMapper->findByAppid($exApp->getAppid());
		} catch (Exception) {
			return [];
		}
	}

	public function setExAppScopeGroup(ExApp $exApp, int $scopeGroup): ?ExAppScope {
		$appId = $exApp->getAppid();
		try {
			return $this->exAppScopeMapper->findByAppidScope($appId, $scopeGroup);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exAppScope = new ExAppScope([
				'appid' => $appId,
				'scope_group' => $scopeGroup,
			]);
			try {
				return $this->exAppScopeMapper->insert($exAppScope);
			} catch (\Exception $e) {
				$this->logger->error('Error while setting ex app scope group: ' . $e->getMessage());
				return null;
			}
		}
	}

	public function removeExAppScopeGroup(ExApp $exApp, int $scopeGroup): ?ExAppScope {
		try {
			$exAppScope = $this->exAppScopeMapper->findByAppidScope($exApp->getAppid(), $scopeGroup);
			return $this->exAppScopeMapper->delete($exAppScope);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Enable ex app
	 *
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function enableExApp(ExApp $exApp): bool {
		try {
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), true) === 1) {
				return true;
			}
		} catch (Exception) {
			return false;
		}
		return false;
	}

	/**
	 * Disable ex app
	 *
	 * @param ExApp $exApp
	 *
	 * @return bool
	 */
	public function disableExApp(ExApp $exApp): bool {
		try {
			if ($this->exAppMapper->updateExAppEnabled($exApp->getAppid(), false) === 1) {
				return true;
			}
		} catch (Exception) {
			return false;
		}
		return false;
	}

	/**
	 * Send status check request to ex app (after verify app registration)
	 *
	 * @param string $appId
	 *
	 * @return array|null
	 */
	public function getAppStatus(string $appId): ?array {
		try {
			// TODO: Send request to ex app, update status and last response time, return status
			$exApp = $this->exAppMapper->findByAppId($appId);
			return json_decode($exApp->getStatus(), true);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function requestToExApp(IRequest $request, string $userId, ExApp $exApp, string $route, string $method = 'POST', array $params = []): array|IResponse {
		try {
 			$exAppConfig = json_decode($exApp->getConfig(), true);
			$url = $exAppConfig['protocol'] . '://' . $exAppConfig['host'] . ':' . $exAppConfig['port'] . $route;
			$this->handleExAppDebug($exApp, $request, true);
			// Check in ex_apps_users
			if (!$this->exAppUserExists($exApp->getAppid(), $userId)) {
				try {
					$this->exAppUserMapper->insert(new ExAppUser([
						'appid' => $exApp->getAppid(),
						'userid' => $userId,
					]));
				} catch (\Exception $e) {
					$this->logger->error('Error while inserting ex app user: ' . $e->getMessage());
					return ['error' => 'Error while inserting ex app user: ' . $e->getMessage()];
				}
			}
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
			$options['headers']['AE-REQUEST-ID'] = $request->getId();

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
			return ['error' => $e->getMessage()];
		}
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
			$dataParams = json_encode($options['json']);
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

	public function validateExAppRequestToNC(IRequest $request, bool $isDav = false): bool {
		try {
			$exApp = $this->exAppMapper->findByAppId($request->getHeader('EX-APP-ID'));
			$enabled = $exApp->getEnabled();
			if (!$enabled) {
				return false;
			}
			$secret = $exApp->getSecret();

			$this->handleExAppDebug($exApp, $request, false);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return false;
		}

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
			return false;
		}
		$headers['AE-SIGN-TIME'] = $signTime;

		$body =  $request->getMethod() . $request->getRequestUri() . json_encode($headers, JSON_UNESCAPED_SLASHES);
		$signature = hash_hmac('sha256', $body, $secret);
		$signatureValid = $signature === $requestSignature;

		if ($signatureValid) {
			if (!$this->verifyDataHash($dataHash)) {
				return false;
			}
			if (!$isDav) {
				try {
					$path = $request->getPathInfo();
				} catch (\Exception $e) {
					$this->logger->error('Error getting path info: ' . $e->getMessage());
					return false;
				}
			} else {
				$path = '/dav/';
			}
			$apiScope = $this->getApiRouteScope($path);

			if ($apiScope === null) {
				return false;
			}
			// If it is not an initialization scope group - check if this endpoint is allowed to be called
			if ($apiScope->getScopeGroup() !== self::BASIC_API_SCOPE) {
				if (!$this->passesScopeCheck($exApp, $apiScope->getScopeGroup())) {
					return false;
				}
				if (!$this->exAppUserExists($exApp->getAppid(), $userId)) {
					return false;
				}
			}
			return $this->finalizeRequestToNC($userId, $exApp);
		}
		$this->logger->error('Invalid signature for ex app: ' . $exApp->getAppid() . ' and user: ' . $userId);
		return false;
	}

	private function finalizeRequestToNC($userId, $exApp): bool {
		if ($userId !== '') {
			$activeUser = $this->userManager->get($userId);
			if ($activeUser === null) {
				$this->logger->error('Requested user does not exists: ' . $userId);
				return false;
			}
			$this->userSession->setUser($activeUser);
		} else {
			$this->userSession->setUser(null);
		}
		$this->updateExAppLastResponseTime($exApp);
		return true;
	}

	private function updateExAppLastResponseTime($exApp): void {
		$exApp->setLastResponseTime(time());
		try {
			$this->exAppMapper->updateLastResponseTime($exApp);
		} catch (\Exception $e) {
			$this->logger->error('Error while updating ex app last response time for ex app: ' . $exApp->getAppid() . '. Error: ' . $e->getMessage());
		}
	}

	public function getNCUsersList(): ?array {
		return array_map(function (IUser $user) {
			return $user->getUID();
		}, $this->userManager->searchDisplayName(''));
	}

	private function exAppUserExists(string $appId, string $userId): bool {
		try {
			$exAppUsers = $this->exAppUserMapper->findByAppidUserid($appId, $userId);
			if (!empty($exAppUsers) && $exAppUsers[0] instanceof ExAppUser) {
				return true;
			}
			return false;
		} catch (Exception) {
			return false;
		}
	}

	public function passesScopeCheck(ExApp $exApp, int $apiScope): bool {
		try {
			$exAppScope = $this->exAppScopeMapper->findByAppidScope($exApp->getAppid(), $apiScope);
			return $exAppScope instanceof ExAppScope;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return false;
		}
	}

	public function getApiRouteScope(string $apiRoute): ?ExAppApiScope {
		try {
//			TODO: Add caching here
			$apiScopes = $this->exAppApiScopeMapper->findAll();
			foreach ($apiScopes as $apiScope) {
				if (str_contains($apiRoute, $apiScope->getApiRoute())) {
					return $apiScope;
				}
			}
			return null;
		} catch (Exception) {
			return null;
		}
	}

	public function registerInitScopes(): bool {
//		TODO: Rewrite to dynamic initialization
		$apiV1Prefix = '/apps/' . Application::APP_ID . '/api/v1';

		$initApiScopes = [
			['api_route' =>  '/cloud/capabilities', 'scope_group' => self::BASIC_API_SCOPE],
			['api_route' =>  $apiV1Prefix . '/files/actions/menu', 'scope_group' => self::BASIC_API_SCOPE],
			['api_route' =>  $apiV1Prefix . '/log', 'scope_group' => self::BASIC_API_SCOPE],
			['api_route' => $apiV1Prefix . '/users', 'scope_group' => self::SYSTEM_API_SCOPE],
			['api_route' =>  $apiV1Prefix . '/ex-app/config', 'scope_group' => self::SYSTEM_API_SCOPE],
			['api_route' =>  $apiV1Prefix . '/ex-app/preference', 'scope_group' => self::BASIC_API_SCOPE],
			['api_route' =>  '/cloud/users', 'scope_group' => self::SYSTEM_API_SCOPE],
			['api_route' =>  '/cloud/groups', 'scope_group' => self::SYSTEM_API_SCOPE],
			['api_route' =>  '/cloud/apps', 'scope_group' => self::SYSTEM_API_SCOPE],
			['api_route' =>  '/dav/', 'scope_group' => self::DAV_API_SCOPE],
		];

		try {
			foreach ($initApiScopes as $apiScope) {
				$this->exAppApiScopeMapper->insertOrUpdate(new ExAppApiScope($apiScope));
			}
			return true;
		} catch (Exception $e) {
			$this->logger->error('Failed to fill init api scopes: ' . $e->getMessage());
			return false;
		}
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
		if ($diff > self::MAX_SIGN_TIME_DIFF) {
			$this->logger->error('AE-SIGN-TIME diff is too big: ' . $diff);
			return false;
		}
		if ($diff < 0) {
			$this->logger->error('AE-SIGN-TIME diff is negative: ' . $diff);
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

	private function handleExAppDebug(ExApp $exApp, IRequest $request, bool $fromNextcloud = true): void {
		$exAppDebugSettings = $this->getExAppDebugSettings($exApp);
		if ($exAppDebugSettings['debug']) {
			$message = $fromNextcloud
				? '[' . Application::APP_ID . '] Nextcloud --> ' . $exApp->getAppid()
				: '[' . Application::APP_ID . '] ' . $exApp->getAppid() . ' --> Nextcloud';
			$aeDebugLogger = $this->getCustomLogger('ae_debug.log');
			$aeDebugLogger->log($exAppDebugSettings['level'], $message, [
				'app' => $exApp->getAppid(),
				'request_info' => $this->buildRequestInfo($request),
			]);
		}
	}
}
