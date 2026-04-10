<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

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
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;
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
		$daemonConfigService = $this->createMock(DaemonConfigService::class);
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
			$daemonConfigService,
			$exAppDeployOptionsService,
			$harpService,
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

		self::assertFalse($this->service->validateExAppRequestToNC($request));
	}
}
