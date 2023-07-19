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

namespace OCA\AppEcosystemV2;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExAppScope;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppScopesService;

use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IConfig;
use OCP\IRequest;

class Capabilities implements ICapability {
	private IConfig $config;
	private IAppManager $appManager;
	private AppEcosystemV2Service $service;
	private ExAppScopesService $exAppScopesService;
	private IRequest $request;

	public function __construct(
		IConfig $config,
		IAppManager $appManager,
		AppEcosystemV2Service $service,
		ExAppScopesService $exAppScopesService,
		IRequest $request,
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
