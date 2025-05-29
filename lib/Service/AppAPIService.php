<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Log\ILogFactory;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

class AppAPIService {

	private IClient $client;

	public function __construct(
		private readonly LoggerInterface     $logger,
		private readonly ILogFactory         $logFactory,
		private readonly IThrottler          $throttler,
		private readonly IConfig             $config,
		IClientService                       $clientService,
		private readonly IUserSession        $userSession,
		private readonly ISession            $session,
		private readonly IUserManager        $userManager,
		private readonly IFactory            $l10nFactory,
		private readonly ExAppService        $exAppService,
		private readonly DockerActions       $dockerActions,
		private readonly ManualActions       $manualActions,
		private readonly AppAPICommonService $commonService,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly HarpService         $harpService,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * Request to ExApp with AppAPI auth headers
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
		$requestData = $this->prepareRequestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
		return $this->requestToExAppInternal($exApp, $method, $requestData['url'], $requestData['options']);
	}

	/**
	 * Request to ExApp with AppAPI auth headers with proper query/body params handling
	 */
	public function requestToExApp2(
		ExApp $exApp,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $queryParams = [],
		array $bodyParams = [],
		array $options = [],
		?IRequest $request = null,
	): array|IResponse {
		$requestData = $this->prepareRequestToExApp2($exApp, $route, $userId, $method, $queryParams, $bodyParams, $options, $request);
		return $this->requestToExAppInternal($exApp, $method, $requestData['url'], $requestData['options']);
	}

	private function requestToExAppInternal(
		ExApp $exApp,
		string $method,
		string $uri,
		#[\SensitiveParameter]
		array $options,
	): array|IResponse {
		try {
			return match ($method) {
				'GET' => $this->client->get($uri, $options),
				'POST' => $this->client->post($uri, $options),
				'PUT' => $this->client->put($uri, $options),
				'DELETE' => $this->client->delete($uri, $options),
				default => ['error' => 'Bad HTTP method'],
			};
		} catch (\Exception $e) {
			$this->logger->warning(sprintf('Error during request to ExApp %s: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @throws \Exception
	 */
	public function requestToExAppAsync(
		ExApp $exApp,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): IPromise {
		$requestData = $this->prepareRequestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
		return $this->requestToExAppInternalAsync($exApp, $method, $requestData['url'], $requestData['options']);
	}

	/**
	 * @throws \Exception if bad HTTP method
	 */
	private function requestToExAppInternalAsync(
		ExApp $exApp,
		string $method,
		string $uri,
		#[\SensitiveParameter]
		array $options,
	): IPromise {
		$promise = match ($method) {
			'GET' => $this->client->getAsync($uri, $options),
			'POST' => $this->client->postAsync($uri, $options),
			'PUT' => $this->client->putAsync($uri, $options),
			'DELETE' => $this->client->deleteAsync($uri, $options),
			default => throw new \Exception('Bad HTTP method'),
		};
		$promise->then(onRejected: function (\Exception $exception) use ($exApp) {
			$this->logger->warning(sprintf('Error during requestToExAppAsync %s: %s', $exApp->getAppid(), $exception->getMessage()), ['exception' => $exception]);
		});
		return $promise;
	}

	private function prepareRequestToExApp(
		ExApp $exApp,
		string $route,
		?string $userId,
		string $method,
		array $params,
		#[\SensitiveParameter]
		array $options,
		?IRequest $request,
	): array {
		$auth = [];
		$url = $this->getExAppUrl($exApp, $exApp->getPort(), $auth);
		if (str_starts_with($route, '/')) {
			$url = $url.$route;
		} else {
			$url = $url.'/'.$route;
		}

		if (isset($options['headers']) && is_array($options['headers'])) {
			$options['headers'] = [...$options['headers'], ...$this->commonService->buildAppAPIAuthHeaders($request, $userId, $exApp)];
		} else {
			$options['headers'] = $this->commonService->buildAppAPIAuthHeaders($request, $userId, $exApp);
		}
		$lang = $this->l10nFactory->findLanguage($exApp->getAppid());
		if (!isset($options['headers']['Accept-Language'])) {
			$options['headers']['Accept-Language'] = $lang;
		}
		$options['nextcloud'] = [
			'allow_local_address' => true, // it's required as we are using ExApp appid as hostname (usually local)
		];
		$options['http_errors'] = false; // do not throw exceptions on 4xx and 5xx responses
		if (!empty($auth)) {
			$options['auth'] = $auth;
			$options['headers'] = $this->swapAuthorizationHeader($options['headers']);
		}
		if (!isset($options['timeout'])) {
			$options['timeout'] = 3;
		}

		if ((!array_key_exists('multipart', $options)) && (count($params)) > 0) {
			if ($method === 'GET') {
				$url .= '?' . http_build_query($params);
			} else {
				$options['json'] = $params;
			}
		}
		return ['url' => $url, 'options' => $options];
	}

	private function prepareRequestToExApp2(
		ExApp $exApp,
		string $route,
		?string $userId,
		string $method,
		array $queryParams,
		array $bodyParams,
		#[\SensitiveParameter]
		array $options,
		?IRequest $request,
	): array {
		$auth = [];
		$url = $this->getExAppUrl($exApp, $exApp->getPort(), $auth);
		if (str_starts_with($route, '/')) {
			$url = $url.$route;
		} else {
			$url = $url.'/'.$route;
		}

		if (isset($options['headers']) && is_array($options['headers'])) {
			$options['headers'] = [...$options['headers'], ...$this->commonService->buildAppAPIAuthHeaders($request, $userId, $exApp)];
		} else {
			$options['headers'] = $this->commonService->buildAppAPIAuthHeaders($request, $userId, $exApp);
		}
		$lang = $this->l10nFactory->findLanguage($exApp->getAppid());
		if (!isset($options['headers']['Accept-Language'])) {
			$options['headers']['Accept-Language'] = $lang;
		}
		$options['nextcloud'] = [
			'allow_local_address' => true, // it's required as we are using ExApp appid as hostname (usually local)
		];
		$options['http_errors'] = false; // do not throw exceptions on 4xx and 5xx responses
		if (!empty($auth)) {
			$options['auth'] = $auth;
			$options['headers'] = $this->swapAuthorizationHeader($options['headers']);
		}
		if (!isset($options['timeout'])) {
			$options['timeout'] = 3;
		}

		if ((!array_key_exists('multipart', $options))) {
			if (count($queryParams) > 0) {
				$url .= '?' . http_build_query($queryParams);
			}
			if ($method !== 'GET' && count($bodyParams) > 0) {
				$options['json'] = $bodyParams;
			}
		}
		return ['url' => $url, 'options' => $options];
	}

	/**
	 * This is required for AppAPI Docker Socket Proxy, as the Basic Auth is already in use by HaProxy,
	 * and the incoming request's Authorization is replaced with X-Original-Authorization header
	 * after HaProxy authenticated.
	 *
	 * @since AppAPI 3.0.0
	 */

	private function swapAuthorizationHeader(array $headers): array {
		foreach ($headers as $key => $value) {
			if (strtoupper($key) === 'AUTHORIZATION') {
				$headers['X-Original-Authorization'] = $value;
				break;
			}
		}
		return $headers;
	}

	/**
	 * AppAPI authentication request validation for Nextcloud:
	 *  - checks if ExApp exists and is enabled
	 *  - checks if ExApp version changed and updates it in database
	 *  - checks if ExApp shared secret valid
	 *
	 * More info in docs: https://cloud-py-api.github.io/app_api/authentication.html
	 */
	public function validateExAppRequestToNC(IRequest $request, bool $isDav = false): bool {
		$delay = $this->throttler->sleepDelayOrThrowOnMax($request->getRemoteAddress(), Application::APP_ID);

		$exAppId = $request->getHeader('EX-APP-ID');
		if (!$exAppId) {
			return false;
		}
		$exApp = $this->exAppService->getExApp($exAppId);
		if ($exApp === null) {
			$this->logger->error(sprintf('ExApp with appId %s not found.', $request->getHeader('EX-APP-ID')));
			// Protection for guessing installed ExApps list
			$this->throttler->registerAttempt(Application::APP_ID, $request->getRemoteAddress(), [
				'appid' => $request->getHeader('EX-APP-ID'),
				'userid' => explode(':', base64_decode($request->getHeader('AUTHORIZATION-APP-API')), 2)[0],
			]);
			return false;
		}

		$authorization = base64_decode($request->getHeader('AUTHORIZATION-APP-API'));
		if ($authorization === false) {
			$this->logger->error('Failed to parse AUTHORIZATION-APP-API');
			return false;
		}
		$userId = explode(':', $authorization, 2)[0];
		$authorizationSecret = explode(':', $authorization, 2)[1];
		$authValid = $authorizationSecret === $exApp->getSecret();

		if ($authValid) {
			if (!$isDav) {
				try {
					$path = $request->getPathInfo();
				} catch (\Exception $e) {
					$this->logger->error(sprintf('Error getting path info. Error: %s', $e->getMessage()), ['exception' => $e]);
					return false;
				}
				if (($this->sanitizeOcsRoute($path) !== '/apps/app_api/ex-app/state') && !$exApp->getEnabled()) {
					$this->logger->error(sprintf('ExApp with appId %s is disabled (%s)', $request->getHeader('EX-APP-ID'), $request->getRequestUri()));
					return false;
				}
			}
			if (!$this->handleExAppVersionChange($request, $exApp)) {
				return false;
			}
			return $this->finalizeRequestToNC($exApp, $userId, $request, $delay);
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
	 */
	private function finalizeRequestToNC(ExApp $exApp, string $userId, IRequest $request, int $delay): bool {
		if ($userId !== '') {
			$activeUser = $this->userManager->get($userId);
			if ($activeUser === null) {
				$this->logger->error(sprintf('Requested user does not exists: %s', $userId));
				return false;
			}
			$this->userSession->setUser($activeUser);
			$this->logImpersonatingRequest($exApp->getAppid());
		} else {
			$this->userSession->setUser(null);
		}
		$this->session->set('app_api', true);

		if ($delay) {
			$this->throttler->resetDelay($request->getRemoteAddress(), Application::APP_ID, [
				'appid' => $request->getHeader('EX-APP-ID'),
				'userid' => $userId,
			]);
		}
		return true;
	}

	/**
	 * Check if the given route has ocs prefix and cut it off
	 */
	private function sanitizeOcsRoute(string $route): string {
		if (preg_match("/\/ocs\/v([12])\.php/", $route, $matches)) {
			return str_replace($matches[0], '', $route);
		}
		return $route;
	}

	private function getCustomLogger(string $name): LoggerInterface {
		$path = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/' . $name;
		return $this->logFactory->getCustomPsrLogger($path);
	}

	private function logImpersonatingRequest(string $appId): void {
		$exAppsImpersonationLogger = $this->getCustomLogger('exapp_impersonation.log');
		$exAppsImpersonationLogger->warning('impersonation request', [
			'app' => $appId,
		]);
	}

	/**
	 * Checks if the ExApp version changed and if it is higher, updates it in the database.
	 */
	public function handleExAppVersionChange(IRequest $request, ExApp $exApp): bool {
		$requestExAppVersion = $request->getHeader('EX-APP-VERSION');
		if ($requestExAppVersion === '') {
			return false;
		}
		if (version_compare($requestExAppVersion, $exApp->getVersion(), '>')) {
			$exApp->setVersion($requestExAppVersion);
			if (!$this->exAppService->updateExApp($exApp, ['version'])) {
				return false;
			}
		}
		return true;
	}

	public function dispatchExAppInitInternal(ExApp $exApp): void {
		$auth = [];
		$initUrl = $this->getExAppUrl($exApp, $exApp->getPort(), $auth) . '/init';
		$options = [
			'headers' => $this->commonService->buildAppAPIAuthHeaders(null, null, $exApp),
			'nextcloud' => [
				'allow_local_address' => true,
			],
		];
		if (!empty($auth)) {
			$options['auth'] = $auth;
		}

		$this->setAppInitProgress($exApp, 0);
		$this->exAppService->enableExAppInternal($exApp);
		try {
			$this->client->post($initUrl, $options);
		} catch (\Exception $e) {
			$statusCode = $e->getCode();
			if (($statusCode === Http::STATUS_NOT_IMPLEMENTED) || ($statusCode === Http::STATUS_NOT_FOUND)) {
				$this->setAppInitProgress($exApp, 100);
			} else {
				$this->setAppInitProgress($exApp, 0, $e->getMessage());
			}
		}
	}

	/**
	 * Dispatch ExApp initialization step, that may take a long time to display the progress of initialization.
	 */
	public function runOccCommand(array $commandParts): bool {
		$args = array_filter($commandParts, static fn ($part) => $part !== null);
		$args[] = '--no-ansi';
		$args[] = '--no-warnings';
		return $this->runOccCommandInternal($args);
	}

	public function runOccCommandInternal(array $args): bool {
		$escapedArgs = array_map('escapeshellarg', $args);
		$args = implode(' ', $escapedArgs);
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$occDirectory = null;
		if (!file_exists("console.php")) {
			$occDirectory = dirname(__FILE__, 5);
		}
		$this->logger->info(sprintf('Calling occ(directory=%s): %s', $occDirectory ?? 'null', $args));
		$process = proc_open('php console.php ' . $args, $descriptors, $pipes, $occDirectory);

		if (!is_resource($process)) {
			$this->logger->error(sprintf('Error calling occ(directory=%s): %s', $occDirectory ?? 'null', $args));
			return false;
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);

		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$returnCode = proc_close($process);

		if ($returnCode !== 0) {
			$this->logger->error(sprintf('Error executing occ command. Return code: %d, stdout: %s, stderr: %s', $returnCode, $stdout, $stderr));
			return false;
		}

		$this->logger->info(sprintf('OCC command executed successfully. stdout: %s, stderr: %s', $stdout, $stderr));

		return true;
	}

	public function heartbeatExApp(
		string $exAppUrl,
		#[\SensitiveParameter]
		array $auth,
		string $appId,
	): bool {
		$heartbeatAttempts = 0;
		$delay = 1;
		if ($appId === Application::TEST_DEPLOY_APPID) {
			$maxHeartbeatAttempts = 60 * $delay; // 1 minute for test deploy app
		} else {
			$maxHeartbeatAttempts = 60 * 10 * $delay; // minutes for container initialization
		}

		$options = [
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'nextcloud' => [
				'allow_local_address' => true,
			],
		];
		if (!empty($auth)) {
			$options['auth'] = $auth;
		}
		$this->logger->info(sprintf('Performing heartbeat on: %s', $exAppUrl . '/heartbeat'));

		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$this->logger->error(sprintf('ExApp with appId %s not found.', $appId));
			return false;
		}
		if (boolval($exApp->getDeployConfig()['harp'] ?? false)) {
			$exApp = $this->exAppService->getExApp($appId);
			$options['headers'] = array_merge(
				$options['headers'],
				$this->commonService->buildAppAPIAuthHeaders(null, null, $exApp),
			);
		}

		$failedHeartbeatCount = 0;
		while ($heartbeatAttempts < $maxHeartbeatAttempts) {
			$heartbeatAttempts++;
			$errorMsg = '';
			$statusCode = 0;
			$exApp = $this->exAppService->getExApp($appId);
			if ($exApp === null) {
				return false;
			}
			try {
				$heartbeatResult = $this->client->get($exAppUrl . '/heartbeat', $options);
				$statusCode = $heartbeatResult->getStatusCode();
				if ($statusCode === 200) {
					$result = json_decode($heartbeatResult->getBody(), true);
					if (isset($result['status']) && $result['status'] === 'ok') {
						$this->logger->info(sprintf('Successful heartbeat on: %s', $exAppUrl . '/heartbeat'));
						return true;
					}
				}
			} catch (\Exception $e) {
				$errorMsg = $e->getMessage();
			}
			$failedHeartbeatCount++;  // Log every 10th failed heartbeat
			if ($failedHeartbeatCount % 10 == 0) {
				$this->logger->warning(
					sprintf('Failed heartbeat on %s for %d times. Most recent status=%d, error: %s', $exAppUrl, $failedHeartbeatCount, $statusCode, $errorMsg)
				);
				$status = $exApp->getStatus();
				if (isset($status['heartbeat_count'])) {
					$status['heartbeat_count'] += $failedHeartbeatCount;
				} else {
					$status['heartbeat_count'] = $failedHeartbeatCount;
				}
				$exApp->setStatus($status);
				$this->exAppService->updateExApp($exApp, ['status']);
			}
			sleep($delay);
		}
		return false;
	}

	public function getExAppUrl(ExApp $exApp, int $port, array &$auth): string {
		if ($exApp->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			return $this->dockerActions->resolveExAppUrl(
				$exApp->getAppid(),
				$exApp->getProtocol(),
				$exApp->getHost(),
				$exApp->getDeployConfig(),
				$port,
				$auth,
			);
		} else {
			return $this->manualActions->resolveExAppUrl(
				$exApp->getAppid(),
				$exApp->getProtocol(),
				$exApp->getHost(),
				$exApp->getDeployConfig(),
				$port,
				$auth,
			);
		}
	}

	public function getExAppDomain(ExApp $exApp): string {
		$auth = [];
		$appFullUrl = $this->getExAppUrl($exApp, 0, $auth);
		$urlComponents = parse_url($appFullUrl);
		return $urlComponents['host'] ?? '';
	}

	/**
	 * Enable ExApp. Sends request to ExApp to update enabled state.
	 * If request fails, ExApp will be disabled.
	 * Removes ExApp from cache.
	 */
	public function enableExApp(ExApp $exApp): bool {
		if (!$this->exAppService->enableExAppInternal($exApp)) {
			return false;
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig === null) {
			$this->logger->error(sprintf('DaemonConfig %s not found.', $exApp->getDaemonConfigName()));
			return false;
		}

		if ($exApp->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$this->dockerActions->initGuzzleClient($daemonConfig);
			$containerName = $this->dockerActions->buildExAppContainerName($exApp->getAppid());
			if (boolval($exApp->getDeployConfig()['harp'] ?? false)) {
				$this->dockerActions->startExApp($this->dockerActions->buildDockerUrl($daemonConfig), $exApp->getAppid(), true);
				if (!$this->dockerActions->waitExAppStart($this->dockerActions->buildDockerUrl($daemonConfig), $exApp->getAppid())) {
					$this->logger->error(sprintf('ExApp %s container startup failed.', $exApp->getAppid()));
					return false;
				}
			} else {
				$this->dockerActions->startContainer($this->dockerActions->buildDockerUrl($daemonConfig), $containerName);
				if (!$this->dockerActions->waitTillContainerStart($containerName, $daemonConfig)) {
					$this->logger->error(sprintf('ExApp %s container startup failed.', $exApp->getAppid()));
					return false;
				}
			}
			if (!$this->dockerActions->healthcheckContainer($containerName, $daemonConfig, true)) {
				$this->logger->error(sprintf('ExApp %s container healthcheck failed.', $exApp->getAppid()));
				return false;
			}
		}

		$auth = [];
		$exAppRootUrl = $this->getExAppUrl($exApp, $exApp->getPort(), $auth);
		if (!$this->heartbeatExApp($exAppRootUrl, $auth, $exApp->getAppid())) {
			$this->logger->error(sprintf('ExApp %s heartbeat failed.', $exApp->getAppid()));
			return false;
		}

		$exAppEnabled = $this->requestToExApp($exApp, '/enabled?enabled=1', null, 'PUT', options: ['timeout' => 60]);
		if ($exAppEnabled instanceof IResponse) {
			$response = json_decode($exAppEnabled->getBody(), true);
			if (!empty($response['error'])) {
				$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $response['error']));
				$this->exAppService->disableExAppInternal($exApp);
				return false;
			}
		} elseif (isset($exAppEnabled['error'])) {
			$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $exAppEnabled['error']));
			$this->exAppService->disableExAppInternal($exApp);
			return false;
		}

		$this->harpService->harpExAppUpdate($daemonConfig, $exApp, true);
		return true;
	}

	/**
	 * Disable ExApp. Sends request to ExApp to update enabled state.
	 * If request fails, disables ExApp in database, cache.
	 */
	public function disableExApp(ExApp $exApp): bool {
		$result = true;
		$exAppDisabled = $this->requestToExApp($exApp, '/enabled?enabled=0', null, 'PUT', options: ['timeout' => 60]);
		if ($exAppDisabled instanceof IResponse) {
			$response = json_decode($exAppDisabled->getBody(), true);
			if (isset($response['error']) && strlen($response['error']) !== 0) {
				$this->logger->error(sprintf('Failed to disable ExApp %s. Error: %s', $exApp->getAppid(), $response['error']));
				$result = false;
			}
		} elseif (isset($exAppDisabled['error'])) {
			$this->logger->error(sprintf('Failed to disable ExApp %s. Error: %s', $exApp->getAppid(), $exAppDisabled['error']));
			$result = false;
		}
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig === null) {
			$this->logger->error(sprintf('DaemonConfig %s not found.', $exApp->getDaemonConfigName()));
			return false;
		}
		if ($exApp->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$this->dockerActions->initGuzzleClient($daemonConfig);
			if (boolval($exApp->getDeployConfig()['harp'] ?? false)) {
				$this->dockerActions->stopExApp($this->dockerActions->buildDockerUrl($daemonConfig), $exApp->getAppid(), true);
			} else {
				$this->dockerActions->stopContainer($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppContainerName($exApp->getAppid()));
			}
		}
		$this->exAppService->disableExAppInternal($exApp);

		if($result) {
			$this->harpService->harpExAppUpdate($daemonConfig, $exApp, false);
		}
		return $result;
	}

	public function setAppInitProgress(ExApp $exApp, int $progress, string $error = ''): void {
		if ($progress < 0 || $progress > 100) {
			throw new \InvalidArgumentException('Invalid ExApp init status progress value');
		}
		$status = $exApp->getStatus();
		if ($progress !== 0 && isset($status['init']) && $status['init'] === 100) {
			return;
		}
		if ($error !== '') {
			$this->logger->error(sprintf('ExApp %s initialization failed. Error: %s', $exApp->getAppid(), $error));
			$status['error'] = $error;
		} else {
			if ($progress === 0) {
				$status['action'] = 'init';
				$status['init_start_time'] = time();
				$status['error'] = '';
			}
			$status['init'] = $progress;
		}
		if ($progress === 100) {
			$status['action'] = '';
			$status['type'] = '';
		}
		$exApp->setStatus($status);
		$this->exAppService->updateExApp($exApp, ['status']);
		if ($progress === 100) {
			$this->enableExApp($exApp);
		}
	}

	public function removeExAppsByDaemonConfigName(DaemonConfig $daemonConfig): void {
		try {
			$targetDaemonExApps = $this->exAppService->getExAppsByDaemonName($daemonConfig->getName());
			if (count($targetDaemonExApps) === 0) {
				return;
			}
			foreach ($targetDaemonExApps as $exApp) {
				$this->disableExApp($exApp);
				if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
					$this->dockerActions->initGuzzleClient($daemonConfig);
					if (boolval($exApp->getDeployConfig()['harp'] ?? false)) {
						$this->dockerActions->removeExApp($this->dockerActions->buildDockerUrl($daemonConfig), $exApp->getAppid(), true);
					} else {
						$this->dockerActions->removeContainer($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppContainerName($exApp->getAppid()));
						$this->dockerActions->removeVolume($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppVolumeName($exApp->getAppid()));
					}
				}
				$this->exAppService->unregisterExApp($exApp->getAppid());
			}
		} catch (Exception) {
		}
	}
}
