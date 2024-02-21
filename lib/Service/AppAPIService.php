<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Notifications\ExNotificationsManager;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
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
		private readonly LoggerInterface         $logger,
		private readonly ILogFactory             $logFactory,
		private readonly IThrottler              $throttler,
		private readonly IConfig                 $config,
		IClientService                           $clientService,
		private readonly IUserSession            $userSession,
		private readonly ISession                $session,
		private readonly IUserManager            $userManager,
		private readonly IFactory				 $l10nFactory,
		private readonly ExNotificationsManager  $exNotificationsManager,
		private readonly ExAppService			 $exAppService,
		private readonly ExAppUsersService       $exAppUsersService,
		private readonly ExAppApiScopeService    $exAppApiScopeService,
		private readonly ExAppScopesService      $exAppScopesService,
		private readonly ExAppConfigService      $exAppConfigService,
		private readonly DockerActions        	 $dockerActions,
		private readonly ManualActions        	 $manualActions,
		private readonly AppAPICommonService	 $commonService,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * Request to ExApp with AppAPI auth headers and ExApp user initialization
	 */
	public function aeRequestToExApp(
		ExApp $exApp,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IResponse {
		try {
			$this->exAppUsersService->setupExAppUser($exApp->getAppid(), $userId);
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error while inserting ExApp %s user. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return ['error' => 'Error while inserting ExApp user: ' . $e->getMessage()];
		}
		return $this->requestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
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
		$this->handleExAppDebug($exApp->getAppid(), $request, true);
		try {
			$auth = [];
			$url = $this->getExAppUrl($exApp, $exApp->getPort(), $auth);
			if (str_starts_with($route, '/')) {
				$url = $url.$route;
			} else {
				$url = $url.'/'.$route;
			}

			if (isset($options['headers']) && is_array($options['headers'])) {
				$options['headers'] = [...$options['headers'], ...$this->commonService->buildAppAPIAuthHeaders($request, $userId, $exApp->getAppid(), $exApp->getVersion(), $exApp->getSecret())];
			} else {
				$options['headers'] = $this->commonService->buildAppAPIAuthHeaders($request, $userId, $exApp->getAppid(), $exApp->getVersion(), $exApp->getSecret());
			}
			$lang = $this->l10nFactory->findLanguage($exApp->getAppid());
			if (!isset($options['headers']['Accept-Language'])) {
				$options['headers']['Accept-Language'] = $lang;
			}
			$options['nextcloud'] = [
				'allow_local_address' => true, // it's required as we are using ExApp appid as hostname (usually local)
			];
			if (!empty($auth)) {
				$options['auth'] = $auth;
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
	 */
	public function validateExAppRequestToNC(IRequest $request, bool $isDav = false): bool {
		$this->throttler->sleepDelayOrThrowOnMax($request->getRemoteAddress(), Application::APP_ID);

		$exApp = $this->exAppService->getExApp($request->getHeader('EX-APP-ID'));
		if ($exApp === null) {
			$this->logger->error(sprintf('ExApp with appId %s not found.', $request->getHeader('EX-APP-ID')));
			// Protection for guessing installed ExApps list
			$this->throttler->registerAttempt(Application::APP_ID, $request->getRemoteAddress(), [
				'appid' => $request->getHeader('EX-APP-ID'),
				'userid' => explode(':', base64_decode($request->getHeader('AUTHORIZATION-APP-API')), 2)[0],
			]);
			return false;
		}

		$this->handleExAppDebug($exApp->getAppid(), $request, false);

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
				$this->logger->error(sprintf('ExApp with appId %s is disabled (%s)', $request->getHeader('EX-APP-ID'), $request->getRequestUri()));
				return false;
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

			$allScopesFlag = (bool)$this->exAppScopesService->getByScope($exApp, ExAppApiScopeService::ALL_API_SCOPE);
			$apiScope = $this->exAppApiScopeService->getApiScopeByRoute($path);

			if (!$allScopesFlag) {
				if ($apiScope === null) {
					$this->logger->error(sprintf('Failed to check apiScope %s', $path));
					return false;
				}

				// BASIC ApiScope is granted to all ExApps (all API routes with BASIC scope group).
				if ($apiScope->getScopeGroup() !== ExAppApiScopeService::BASIC_API_SCOPE) {
					if (!$this->exAppScopesService->passesScopeCheck($exApp, $apiScope->getScopeGroup())) {
						$this->logger->error(sprintf('ExApp %s not passed scope group check %s', $exApp->getAppid(), $path));
						return false;
					}
				}
			}

			// For APIs that not assuming work under user context we do not check ExApp users
			if ((!$exApp->getIsSystem()) && (($apiScope === null) or ($apiScope->getUserCheck()))) {
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

	private function getExAppDebugSettings(string $appId): array {
		$exAppConfigs = $this->exAppConfigService->getAppConfigValues($appId, ['debug', 'loglevel']);
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

	private function getCustomLogger(string $name): LoggerInterface {
		$path = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/' . $name;
		return $this->logFactory->getCustomPsrLogger($path);
	}

	private function handleExAppDebug(string $appId, ?IRequest $request, bool $fromNextcloud = true): void {
		$exAppDebugSettings = $this->getExAppDebugSettings($appId);
		if ($exAppDebugSettings['debug']) {
			$message = $fromNextcloud
				? '[' . Application::APP_ID . '] Nextcloud --> ' . $appId
				: '[' . Application::APP_ID . '] ' . $appId . ' --> Nextcloud';
			$aeDebugLogger = $this->getCustomLogger('aa_debug.log');
			$aeDebugLogger->log($exAppDebugSettings['level'], $message, [
				'app' => $appId,
				'request_info' => $request instanceof IRequest ? $this->buildRequestInfo($request) : 'CLI request',
			]);
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
	 */
	public function handleExAppVersionChange(IRequest $request, ExApp $exApp): bool {
		$requestExAppVersion = $request->getHeader('EX-APP-VERSION');
		$versionValid = $exApp->getVersion() === $requestExAppVersion;
		if (!$versionValid) {
			// Update ExApp version
			$oldVersion = $exApp->getVersion();
			$exApp->setVersion($requestExAppVersion);
			if (!$this->exAppService->updateExApp($exApp, ['version'])) {
				return false;
			}
			$this->disableExApp($exApp);
			$this->exNotificationsManager->sendAdminsNotification($exApp->getAppid(), [
				'object' => 'ex_app_update',
				'object_id' => $exApp->getAppid(),
				'subject_type' => 'ex_app_version_update',
				'subject_params' => [
					'rich_subject' => 'ExApp updated, action required!',
					'rich_subject_params' => [],
					'rich_message' => sprintf(
						'ExApp %s disabled due to update from %s to %s. Manual re-enable required.',
						$exApp->getAppid(),
						$oldVersion,
						$exApp->getVersion()),
					'rich_message_params' => [],
				],
			]);
			return false;
		}
		return true;
	}

	public function dispatchExAppInitInternal(ExApp $exApp): void {
		$auth = [];
		$initUrl = $this->getExAppUrl($exApp, $exApp->getPort(), $auth) . '/init';
		$options = [
			'headers' => $this->commonService->buildAppAPIAuthHeaders(null, null, $exApp->getAppid(), $exApp->getVersion(), $exApp->getSecret()),
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
	public function runOccCommand(string $command): bool {
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$args = array_map(function ($arg) {
			return escapeshellarg($arg);
		}, explode(' ', $command));
		$args[] = '--no-ansi --no-warnings';
		$args = implode(' ', $args);
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
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return true;
	}

	public function heartbeatExApp(string $exAppUrl, array $auth): bool {
		$heartbeatAttempts = 0;
		$delay = 1;
		$maxHeartbeatAttempts = 60 * 10 * $delay; // minutes for container initialization

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

		$failedHeartbeatCount = 0;
		while ($heartbeatAttempts < $maxHeartbeatAttempts) {
			$heartbeatAttempts++;
			$errorMsg = '';
			$statusCode = 0;
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

	/**
	 * Enable ExApp. Sends request to ExApp to update enabled state.
	 * If request fails, ExApp will be disabled.
	 * Removes ExApp from cache.
	 */
	public function enableExApp(ExApp $exApp): bool {
		if ($this->exAppService->enableExAppInternal($exApp)) {
			$exAppEnabled = $this->requestToExApp($exApp, '/enabled?enabled=1', null, 'PUT');
			if ($exAppEnabled instanceof IResponse) {
				$response = json_decode($exAppEnabled->getBody(), true);
				if (empty($response['error'])) {
					$this->exAppService->updateExApp($exApp, ['last_check_time']);
				} else {
					$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $response['error']));
					$this->exAppService->disableExAppInternal($exApp);
					return false;
				}
			} elseif (isset($exAppEnabled['error'])) {
				$this->logger->error(sprintf('Failed to enable ExApp %s. Error: %s', $exApp->getAppid(), $exAppEnabled['error']));
				$this->exAppService->disableExAppInternal($exApp);
				return false;
			}
		}
		return true;
	}

	/**
	 * Disable ExApp. Sends request to ExApp to update enabled state.
	 * If request fails, disables ExApp in database, cache.
	 */
	public function disableExApp(ExApp $exApp): bool {
		$result = true;
		$exAppDisabled = $this->requestToExApp($exApp, '/enabled?enabled=0', null, 'PUT');
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
		$this->exAppService->disableExAppInternal($exApp);
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
		$exApp->setLastCheckTime(time());
		$this->exAppService->updateExApp($exApp, ['status', 'last_check_time']);
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
				if ($daemonConfig->getAcceptsDeployId() === 'docker-install') {
					$this->dockerActions->initGuzzleClient($daemonConfig);
					$this->dockerActions->removeContainer($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppContainerName($exApp->getAppid()));
					$this->dockerActions->removeVolume($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppVolumeName($exApp->getAppid()));
				}
				$this->exAppService->unregisterExApp($exApp->getAppid());
			}
		} catch (Exception) {
		}
	}
}
