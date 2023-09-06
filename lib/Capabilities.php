<?php

declare(strict_types=1);

namespace OCA\AppAPI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExAppScope;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppScopesService;

use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IConfig;
use OCP\IRequest;

class Capabilities implements ICapability {
	private IConfig $config;
	private IAppManager $appManager;
	private AppAPIService $service;
	private ExAppScopesService $exAppScopesService;
	private IRequest $request;

	public function __construct(
		IConfig            $config,
		IAppManager        $appManager,
		AppAPIService      $service,
		ExAppScopesService $exAppScopesService,
		IRequest           $request,
	) {
		$this->config = $config;
		$this->appManager = $appManager;
		$this->service = $service;
		$this->request = $request;
		$this->exAppScopesService = $exAppScopesService;
	}

	public function getCapabilities(): array {
		$capabilities = [
			'loglevel' => intval($this->config->getSystemValue('loglevel', 2)),
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
		];
		$this->attachExAppScopes($capabilities);
		return [
			'app_ecosystem_v2' => $capabilities,
		];
	}

	private function attachExAppScopes(&$capabilities): void {
		$appId = $this->request->getHeader('EX-APP-ID');
		if ($appId !== '') {
			$exApp = $this->service->getExApp($appId);
			if ($exApp !== null) {
				$capabilities['scopes'] = array_map(function (ExAppScope $scope) {
					return intval($scope->getScopeGroup());
				}, $this->exAppScopesService->getExAppScopes($exApp));
			}
		}
	}
}
