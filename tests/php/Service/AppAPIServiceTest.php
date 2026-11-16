<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\DeployActions\ManualActions;
use OCA\AppAPI\Service\AppAPICommonService;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AppAPIServiceTest extends TestCase {

	private const TEST_SECRET = 'test_secret_value_12345';

	private AppAPIService $service;
	private LoggerInterface&MockObject $logger;
	private ExAppService&MockObject $exAppService;
	private DockerActions&MockObject $dockerActions;
	private KubernetesActions&MockObject $kubernetesActions;
	private ManualActions&MockObject $manualActions;
	private IClient&MockObject $client;
	private AppAPICommonService&MockObject $commonService;
	private IThrottler&MockObject $throttler;
	private IConfig&MockObject $config;
	private IUserSession&MockObject $userSession;
	private ISession&MockObject $session;
	private IUserManager&MockObject $userManager;
	private DaemonConfigService&MockObject $daemonConfigService;
	private AuditLogger&MockObject $auditLogger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$logFactory = $this->createMock(\OCP\Log\ILogFactory::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->config = $this->createMock(IConfig::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->session = $this->createMock(ISession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$l10nFactory = $this->createMock(IFactory::class);
		$this->exAppService = $this->createMock(ExAppService::class);
		$this->dockerActions = $this->createMock(DockerActions::class);
		$this->kubernetesActions = $this->createMock(KubernetesActions::class);
		$this->manualActions = $this->createMock(ManualActions::class);
		$this->commonService = $this->createMock(AppAPICommonService::class);
		$this->daemonConfigService = $this->createMock(DaemonConfigService::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);
		$exAppDeployOptionsService = $this->createMock(ExAppDeployOptionsService::class);
		$harpService = $this->createMock(HarpService::class);

		$this->service = new AppAPIService(
			$this->logger,
			$logFactory,
			$this->throttler,
			$this->config,
			$clientService,
			$this->userSession,
			$this->session,
			$this->userManager,
			$l10nFactory,
			$this->exAppService,
			$this->dockerActions,
			$this->kubernetesActions,
			$this->manualActions,
			$this->commonService,
			$this->daemonConfigService,
			$exAppDeployOptionsService,
			$harpService,
			$this->auditLogger,
		);
	}

	private function createExApp(
		string $appId = 'test_app',
		bool $enabled = true,
		string $version = '1.0.0',
		string $statusType = '',
	): ExApp {
		$exApp = new ExApp();
		$exApp->setAppid($appId);
		$exApp->setVersion($version);
		$exApp->setName('Test App');
		$exApp->setPort(23000);
		$exApp->setSecret(self::TEST_SECRET);
		$exApp->setEnabled($enabled ? 1 : 0);
		$exApp->setStatus([
			'deploy' => 100,
			'init' => 0,
			'action' => '',
			'type' => $statusType,
			'error' => '',
		]);
		$exApp->setDaemonConfigName('test_daemon');
		$exApp->setProtocol('http');
		$exApp->setHost('127.0.0.1');
		$exApp->setAcceptsDeployId('manual-install');
		$exApp->setDeployConfig([]);
		return $exApp;
	}

	private function createMockRequest(string $appId, string $version, string $secret, string $path): MockObject&\OCP\IRequest {
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$request->method('getHeader')->willReturnCallback(function (string $name) use ($appId, $version, $secret) {
			return match ($name) {
				'EX-APP-ID' => $appId,
				'EX-APP-VERSION' => $version,
				'AUTHORIZATION-APP-API' => base64_encode(':' . $secret),
				default => '',
			};
		});
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn($path);
		return $request;
	}

	/**
	 * Set up mocks needed for getExAppUrl to resolve without errors.
	 */
	private function setupExAppUrlMocks(): void {
		// Make getExAppUrl take the manual actions path and return a test URL
		$this->dockerActions->method('getAcceptsDeployId')->willReturn('docker-install');
		$this->kubernetesActions->method('getAcceptsDeployId')->willReturn('kubernetes-install');
		$this->manualActions->method('getAcceptsDeployId')->willReturn('manual-install');
		$this->manualActions->method('resolveExAppUrl')->willReturn('http://localhost:23000');
	}

	public static function reportedVersionProvider(): array {
		return [
			'newer version' => ['1.1.0', true],
			'same version' => ['1.0.0', false],
			'older version' => ['0.9.0', false],
		];
	}

	#[DataProvider('reportedVersionProvider')]
	public function testHandleExAppVersionChangeLogsANewerVersion(string $reportedVersion, bool $logged): void {
		$exApp = $this->createExApp();
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getHeader')->with('EX-APP-VERSION')->willReturn($reportedVersion);
		$this->exAppService->method('updateExApp')->willReturn(true);
		$this->auditLogger->expects($logged ? self::once() : self::never())->method('log')->with(
			Operation::ExAppUpdated,
			'ExApp %s reported a newer version',
			['appId' => 'test_app', 'oldVersion' => '1.0.0', 'newVersion' => $reportedVersion],
		);

		self::assertTrue($this->service->handleExAppVersionChange($request, $exApp));
	}

	/**
	 * Test that dispatchExAppInitInternal aborts when enableExAppInternal fails.
	 * This is the primary fix for issue #803.
	 */
	public function testDispatchExAppInitInternalAbortsOnEnableFail(): void {
		$exApp = $this->createExApp(enabled: false);

		$this->setupExAppUrlMocks();

		$this->exAppService->expects(self::once())
			->method('enableExAppInternal')
			->with($exApp)
			->willReturn(false);

		$this->exAppService->method('updateExApp')->willReturn(true);

		$this->commonService->method('buildAppAPIAuthHeaders')
			->willReturn(['AUTHORIZATION-APP-API' => 'test']);

		// The HTTP client must NOT be called if enable fails
		$this->client->expects(self::never())
			->method('post');

		$this->service->dispatchExAppInitInternal($exApp);
	}

	/**
	 * Test that dispatchExAppInitInternal proceeds normally when enable succeeds.
	 */
	public function testDispatchExAppInitInternalProceedsOnEnableSuccess(): void {
		$exApp = $this->createExApp(enabled: false);

		$this->setupExAppUrlMocks();

		$this->exAppService->expects(self::once())
			->method('enableExAppInternal')
			->with($exApp)
			->willReturn(true);

		$this->exAppService->method('updateExApp')->willReturn(true);

		$this->commonService->method('buildAppAPIAuthHeaders')
			->willReturn(['AUTHORIZATION-APP-API' => 'test']);

		// POST /init SHOULD be called when enable succeeds
		$this->client->expects(self::once())
			->method('post');

		$this->service->dispatchExAppInitInternal($exApp);
	}

	/**
	 * Test that a disabled ExApp CAN access the /ex-app/state endpoint.
	 * This was already working before; verify it's still working.
	 */
	public function testValidateDisabledExAppCanAccessExAppState(): void {
		$exApp = $this->createExApp('test_app', false);
		$request = $this->createMockRequest('test_app', '1.0.0', self::TEST_SECRET,
			'/ocs/v1.php/apps/app_api/ex-app/state');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);

		self::assertTrue($this->service->validateExAppRequestToNC($request));
	}

	/**
	 * Test that a disabled ExApp being updated CAN access the new /ex-app/status endpoint.
	 * This is part of the fix for issue #803.
	 */
	public function testValidateDisabledExAppDuringUpdateCanAccessExAppStatus(): void {
		$exApp = $this->createExApp('test_app', false, statusType: 'update');
		$request = $this->createMockRequest('test_app', '1.0.0', self::TEST_SECRET,
			'/ocs/v1.php/apps/app_api/ex-app/status');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);

		self::assertTrue($this->service->validateExAppRequestToNC($request));
	}

	/**
	 * Test that a disabled ExApp being installed CAN access the new /ex-app/status endpoint.
	 * This is part of the fix for issue #803.
	 */
	public function testValidateDisabledExAppDuringInstallCanAccessExAppStatus(): void {
		$exApp = $this->createExApp('test_app', false, statusType: 'install');
		$request = $this->createMockRequest('test_app', '1.0.0', self::TEST_SECRET,
			'/ocs/v1.php/apps/app_api/ex-app/status');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);

		self::assertTrue($this->service->validateExAppRequestToNC($request));
	}

	/**
	 * Test that a disabled ExApp NOT in install/update CANNOT access status endpoints.
	 * Prevents a disabled ExApp from re-enabling itself via set_init_status(100).
	 */
	public function testValidateDisabledExAppCannotAccessStatusWhenNotInitializing(): void {
		$exApp = $this->createExApp('test_app', false);  // statusType defaults to ''
		$request = $this->createMockRequest('test_app', '1.0.0', self::TEST_SECRET,
			'/ocs/v1.php/apps/app_api/ex-app/status');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);

		self::assertFalse($this->service->validateExAppRequestToNC($request));
	}

	/**
	 * Test that a disabled ExApp is STILL rejected for non-exempt endpoints even during update.
	 */
	public function testValidateDisabledExAppRejectedForNonExemptEndpoint(): void {
		$exApp = $this->createExApp('test_app', false, statusType: 'update');
		$request = $this->createMockRequest('test_app', '1.0.0', self::TEST_SECRET,
			'/ocs/v1.php/apps/app_api/api/v1/log');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->validateExAppRequestToNC($request));
	}

	/**
	 * Test that an enabled ExApp passes validation for any endpoint.
	 */
	public function testValidateEnabledExAppAllowedEverywhere(): void {
		$exApp = $this->createExApp('test_app', true);
		$request = $this->createMockRequest('test_app', '1.0.0', self::TEST_SECRET,
			'/ocs/v1.php/apps/app_api/api/v1/log');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertTrue($this->service->validateExAppRequestToNC($request));
	}

	/**
	 * Test that an invalid secret is rejected regardless of the path.
	 */
	public function testValidateInvalidSecretRejected(): void {
		$exApp = $this->createExApp('test_app', true);
		$request = $this->createMockRequest('test_app', '1.0.0', 'wrong_secret',
			'/ocs/v1.php/apps/app_api/ex-app/status');

		$this->exAppService->method('getExApp')->with('test_app')->willReturn($exApp);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppAuthFailed,
			'Failed AppAPI authentication of ExApp %s',
			['appId' => 'test_app', 'userId' => '', 'reason' => 'invalid_secret'],
		);

		self::assertFalse($this->service->validateExAppRequestToNC($request));
	}

	private function createResponse(string $body, int $statusCode = 200): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	private function setupReachableManualExApp(ExApp $exApp, string|\Exception $enabledResponse, ?DaemonConfig $daemonConfig = null): void {
		$this->setupExAppUrlMocks();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($daemonConfig ?? new DaemonConfig(['name' => 'test_daemon']));
		$this->exAppService->method('getExApp')->willReturn($exApp);
		$this->commonService->method('buildAppAPIAuthHeaders')->willReturn([]);
		$this->client->method('get')->willReturn($this->createResponse('{"status":"ok"}'));
		if ($enabledResponse instanceof \Exception) {
			$this->client->method('put')->willThrowException($enabledResponse);
		} else {
			$this->client->method('put')->willReturn($this->createResponse($enabledResponse));
		}
	}

	private function dockerSocketProxyDaemon(): DaemonConfig {
		$this->dockerActions->method('buildExAppContainerName')->willReturnCallback(static fn (string $appId): string => 'nc_app_' . $appId);
		return new DaemonConfig(['name' => 'test_daemon', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => []]);
	}

	public function testEnableExAppDoesNotStartTheContainerOfADockerSocketProxyExApp(): void {
		$exApp = $this->createExApp(enabled: false);
		$exApp->setAcceptsDeployId('docker-install');
		$this->dockerActions->method('resolveExAppUrl')->willReturn('http://test_app:23000');
		$this->setupExAppUrlMocks();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($this->dockerSocketProxyDaemon());
		$this->exAppService->method('getExApp')->willReturn($exApp);
		$this->commonService->method('buildAppAPIAuthHeaders')->willReturn([]);
		$this->client->expects(self::once())->method('get')->willReturn($this->createResponse('{"status":"ok"}'));
		$this->client->method('put')->willReturn($this->createResponse('{}'));
		$this->exAppService->method('enableExAppInternal')->willReturn(true);
		$this->dockerActions->expects(self::never())->method('startExApp');
		$this->exAppService->expects(self::never())->method('disableExAppInternal');

		self::assertTrue($this->service->enableExApp($exApp));
	}

	public static function unansweredDockerSocketProxyHeartbeatProvider(): array {
		return [
			'container stopped' => [new \Exception('Connection refused'), false],
			'container answers an error' => ['503', false],
			'something else answers' => ['200', false],
			'enabled ExApp, container stopped' => [new \Exception('Connection refused'), true],
		];
	}

	#[DataProvider('unansweredDockerSocketProxyHeartbeatProvider')]
	public function testEnableExAppOfADockerSocketProxyExAppThatDoesNotAnswerFailsAtOnce(\Exception|string $heartbeat, bool $wasEnabled): void {
		$exApp = $this->createExApp(enabled: $wasEnabled);
		$exApp->setAcceptsDeployId('docker-install');
		$this->dockerActions->method('resolveExAppUrl')->willReturn('http://test_app:23000');
		$this->dockerActions->expects(self::never())->method('startExApp');
		$this->setupExAppUrlMocks();
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn($this->dockerSocketProxyDaemon());
		$this->exAppService->method('getExApp')->willReturn($exApp);
		$this->commonService->method('buildAppAPIAuthHeaders')->willReturn([]);
		if ($heartbeat instanceof \Exception) {
			$this->client->expects(self::once())->method('get')
				->with(self::anything(), self::callback(static fn (array $options): bool => $options['timeout'] === 10))
				->willThrowException($heartbeat);
		} else {
			$this->client->expects(self::once())->method('get')
				->with(self::anything(), self::callback(static fn (array $options): bool => $options['timeout'] === 10))
				->willReturn($this->createResponse('{"status":"busy"}', (int)$heartbeat));
		}
		$this->exAppService->method('enableExAppInternal')->willReturn(true);
		$this->exAppService->expects(self::once())->method('disableExAppInternal')->with($exApp)->willReturn(true);
		$error = 'The ExApp does not answer the heartbeat, and its daemon uses Docker Socket Proxy, which is no longer supported. Start container nc_app_test_app by hand, or uninstall the ExApp keeping its data and install it on a HaRP daemon on the same Docker host.';
		$this->exAppService->expects(self::once())->method('setStatusError')->with($exApp, $error);
		$this->logger->expects(self::once())->method('error')->with('ExApp test_app: ' . $error);
		if ($wasEnabled) {
			$this->auditLogger->expects(self::once())->method('log')->with(Operation::ExAppDisabled, 'ExApp %s was disabled', ['appId' => 'test_app']);
		} else {
			$this->auditLogger->expects(self::never())->method('log');
		}

		self::assertFalse($this->service->enableExApp($exApp));
	}

	public function testDisableExAppDoesNotStopTheContainerOfADockerSocketProxyExApp(): void {
		$exApp = $this->createExApp();
		$exApp->setAcceptsDeployId('docker-install');
		$this->dockerActions->method('resolveExAppUrl')->willReturn('http://test_app:23000');
		$this->setupReachableManualExApp($exApp, '{}', $this->dockerSocketProxyDaemon());
		$this->exAppService->method('disableExAppInternal')->willReturn(true);
		$this->dockerActions->expects(self::never())->method('stopExApp');
		$this->logger->expects(self::once())->method('warning')
			->with('Daemon "test_daemon" uses Docker Socket Proxy, which is no longer supported, so container nc_app_test_app is not stopped.');

		self::assertTrue($this->service->disableExApp($exApp));
	}

	public function testEnableExAppLogsTheExApp(): void {
		$exApp = $this->createExApp(enabled: false);
		$this->setupReachableManualExApp($exApp, '{}');
		$this->exAppService->method('enableExAppInternal')->willReturn(true);
		$this->auditLogger->expects(self::once())->method('log')
			->with(Operation::ExAppEnabled, 'ExApp %s was enabled', ['appId' => 'test_app']);

		self::assertTrue($this->service->enableExApp($exApp));
	}

	public static function refusedEnableProvider(): array {
		return [
			'enabled ExApp answers an error' => [true, '{"error":"missing configuration"}'],
			'enabled ExApp cannot be reached' => [true, null],
			'disabled ExApp answers an error' => [false, '{"error":"missing configuration"}'],
			'disabled ExApp cannot be reached' => [false, null],
		];
	}

	#[DataProvider('refusedEnableProvider')]
	public function testEnableExAppLogsDisabledOnlyForAnEnabledExAppThatRefuses(bool $wasEnabled, ?string $answer): void {
		$exApp = $this->createExApp(enabled: $wasEnabled);
		$this->setupReachableManualExApp($exApp, $answer ?? new \Exception('Connection refused'));
		$this->exAppService->method('enableExAppInternal')->willReturnCallback(static function (ExApp $exApp): bool {
			$exApp->setEnabled(1);
			return true;
		});
		$this->exAppService->expects(self::once())->method('disableExAppInternal')->with($exApp)->willReturn(true);
		$this->auditLogger->expects($wasEnabled ? self::once() : self::never())->method('log')
			->with(Operation::ExAppDisabled, 'ExApp %s was disabled', ['appId' => 'test_app']);

		self::assertFalse($this->service->enableExApp($exApp));
	}

	public function testEnableExAppDoesNotLogWhenItCannotBeStored(): void {
		$exApp = $this->createExApp(enabled: false);
		$this->exAppService->method('enableExAppInternal')->willReturn(false);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->enableExApp($exApp));
	}

	public function testDisableExAppLogsTheExApp(): void {
		$exApp = $this->createExApp();
		$this->setupReachableManualExApp($exApp, '{}');
		$this->exAppService->method('disableExAppInternal')->willReturn(true);
		$this->auditLogger->expects(self::once())->method('log')
			->with(Operation::ExAppDisabled, 'ExApp %s was disabled', ['appId' => 'test_app']);

		self::assertTrue($this->service->disableExApp($exApp));
	}

	public function testDisableExAppDoesNotLogAnExAppThatWasAlreadyDisabled(): void {
		$exApp = $this->createExApp(enabled: false);
		$this->setupReachableManualExApp($exApp, '{}');
		$this->exAppService->method('disableExAppInternal')->willReturn(true);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertTrue($this->service->disableExApp($exApp));
	}

	public function testDisableExAppLogsAnExAppThatCouldNotBeToldAboutIt(): void {
		$exApp = $this->createExApp();
		$this->setupReachableManualExApp($exApp, '{"error":"busy"}');
		$this->exAppService->expects(self::once())->method('disableExAppInternal')->with($exApp)->willReturn(true);
		$this->auditLogger->expects(self::once())->method('log')
			->with(Operation::ExAppDisabled, 'ExApp %s was disabled', ['appId' => 'test_app']);

		self::assertFalse($this->service->disableExApp($exApp));
	}

	public function testDisableExAppDoesNotLogWhenItCannotBeStored(): void {
		$exApp = $this->createExApp();
		$this->setupReachableManualExApp($exApp, '{}');
		$this->exAppService->method('disableExAppInternal')->willReturn(false);
		$this->auditLogger->expects(self::never())->method('log');

		$this->service->disableExApp($exApp);
	}

	public function testDisableExAppDoesNotLogWithoutItsDaemon(): void {
		$exApp = $this->createExApp();
		$this->setupExAppUrlMocks();
		$this->commonService->method('buildAppAPIAuthHeaders')->willReturn([]);
		$this->client->method('put')->willReturn($this->createResponse('{}'));
		$this->daemonConfigService->method('getDaemonConfigByName')->willReturn(null);
		$this->exAppService->expects(self::never())->method('disableExAppInternal');
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->disableExApp($exApp));
	}

	public static function removeExAppsByDaemonProvider(): array {
		return [
			'removed' => ['', true],
			'failed' => ['Failed to remove ExApp', false],
		];
	}

	#[DataProvider('removeExAppsByDaemonProvider')]
	public function testRemoveExAppsByDaemonConfigNameLogsTheDeletedData(string $removeExAppError, bool $logged): void {
		$exApp = $this->createExApp();
		$exApp->setAcceptsDeployId('docker-install');
		$deployConfig = ['harp' => ['frp_address' => 'appapi-harp:8782']];
		$exApp->setDeployConfig($deployConfig);
		$daemonConfig = new DaemonConfig(['name' => 'test_daemon', 'accepts_deploy_id' => 'docker-install', 'deploy_config' => $deployConfig]);
		$this->setupReachableManualExApp($exApp, '{}');
		$this->exAppService->method('getExAppsByDaemonName')->with('test_daemon')->willReturn([$exApp]);
		$this->exAppService->method('disableExAppInternal')->willReturn(true);
		$this->dockerActions->method('getAcceptsDeployId')->willReturn('docker-install');
		$this->dockerActions->method('removeExApp')->willReturn($removeExAppError);
		$this->exAppService->expects($logged ? self::once() : self::never())->method('logExAppDataDeleted')->with($exApp);
		$this->exAppService->expects(self::once())->method('unregisterExApp')->with('test_app');

		$this->service->removeExAppsByDaemonConfigName($daemonConfig);
	}

	public function testRemoveExAppsByDaemonConfigNameKeepsTheContainersOfADockerSocketProxyDaemon(): void {
		$exApp = $this->createExApp();
		$exApp->setAcceptsDeployId('docker-install');
		$this->setupReachableManualExApp($exApp, '{}');
		$this->exAppService->method('getExAppsByDaemonName')->with('test_daemon')->willReturn([$exApp]);
		$this->exAppService->method('disableExAppInternal')->willReturn(true);
		$this->dockerActions->expects(self::never())->method('removeExApp');
		$this->exAppService->expects(self::never())->method('logExAppDataDeleted');
		$this->exAppService->expects(self::once())->method('unregisterExApp')->with('test_app');

		$this->service->removeExAppsByDaemonConfigName($this->dockerSocketProxyDaemon());
	}

	private function createRequestForUser(string $appId, string $userId, string $secret): MockObject&\OCP\IRequest {
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => match ($name) {
			'EX-APP-ID' => $appId,
			'EX-APP-VERSION' => '1.0.0',
			'AUTHORIZATION-APP-API' => base64_encode($userId . ':' . $secret),
			default => '',
		});
		$request->method('getPathInfo')->willReturn('/ocs/v1.php/apps/app_api/api/v1/log');
		return $request;
	}

	public function testValidateLogsAWrongSecretWithTheClaimedUser(): void {
		$this->exAppService->method('getExApp')->with('test_app')->willReturn($this->createExApp());
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppAuthFailed,
			'Failed AppAPI authentication of ExApp %s',
			['appId' => 'test_app', 'userId' => 'alice', 'reason' => 'invalid_secret'],
		);

		self::assertFalse($this->service->validateExAppRequestToNC($this->createRequestForUser('test_app', 'alice', 'wrong_secret')));
	}

	public function testValidateLogsAnUnknownExApp(): void {
		$this->exAppService->method('getExApp')->with('unknown_app')->willReturn(null);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->throttler->expects(self::once())->method('registerAttempt');
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppAuthFailed,
			'Failed AppAPI authentication of an unknown ExApp',
			['appId' => 'unknown_app', 'userId' => 'alice', 'reason' => 'unknown_ex_app'],
		);

		self::assertFalse($this->service->validateExAppRequestToNC($this->createRequestForUser('unknown_app', 'alice', 'any_secret')));
	}

	public function testValidateLogsARequestValidatedTwiceOnce(): void {
		$this->exAppService->method('getExApp')->with('test_app')->willReturn($this->createExApp());
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->auditLogger->expects(self::once())->method('log');
		$request = $this->createRequestForUser('test_app', 'alice', 'wrong_secret');

		self::assertFalse($this->service->validateExAppRequestToNC($request));
		self::assertFalse($this->service->validateExAppRequestToNC($request));
	}

	public function testValidateLogsNoUserForAnAuthorizationWithoutOne(): void {
		$this->exAppService->method('getExApp')->with('test_app')->willReturn($this->createExApp());
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => match ($name) {
			'EX-APP-ID' => 'test_app',
			'AUTHORIZATION-APP-API' => base64_encode('a_secret_sent_without_a_user'),
			default => '',
		});
		$this->auditLogger->expects(self::once())->method('log')->with(
			Operation::ExAppAuthFailed,
			'Failed AppAPI authentication of ExApp %s',
			['appId' => 'test_app', 'userId' => '', 'reason' => 'invalid_secret'],
		);

		self::assertFalse($this->service->validateExAppRequestToNC($request));
	}

	public function testValidateDoesNotLogARequestWithoutAnExAppId(): void {
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$this->auditLogger->expects(self::never())->method('log');

		self::assertFalse($this->service->validateExAppRequestToNC($this->createRequestForUser('', 'alice', 'any_secret')));
	}
}
