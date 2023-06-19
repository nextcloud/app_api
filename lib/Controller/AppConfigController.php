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

namespace OCA\AppEcosystemV2\Controller;


use OCA\AppEcosystemV2\Db\ExAppConfig;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\ExAppConfigService;

class AppConfigController extends OCSController {
	private ExAppConfigService $exAppConfigService;
	protected $request;

	public function __construct(
		IRequest $request,
		ExAppConfigService $exAppConfigService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->exAppConfigService = $exAppConfigService;
	}

	/**
	 * @AEAuth
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $configKey
	 * @param mixed $configValue
	 * @param string $format
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	public function setAppConfigValue(string $configKey, mixed $configValue, string $format = 'json'): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $configValue);
		if ($result instanceof ExAppConfig) {
			return $this->buildResponse(new DataResponse(1, Http::STATUS_OK), $format);
		}
		throw new OCSBadRequestException('Error setting app config value');
	}

	/**
	 * @AEAuth
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $configKeys
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getAppConfigValues(array $configKeys, string $format = 'json'): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppConfigService->getAppConfigValues($appId, $configKeys);
		return $this->buildResponse(new DataResponse($result, Http::STATUS_OK), $format);
	}

	/**
	 * @AEAuth
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getAppConfigKeys(string $format = 'json'): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$appConfigKeys = $this->exAppConfigService->getAppConfigKeys($appId);
		return $this->buildResponse(new DataResponse($appConfigKeys, Http::STATUS_OK), $format);
	}

	/**
	 * @AEAuth
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $configKeys
	 * @param string $format
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	public function deleteAppConfigValues(array $configKeys, string $format = 'json'): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppConfigService->deleteAppConfigValues($configKeys, $appId);
		if ($result !== -1) {
			return $this->buildResponse(new DataResponse($result, Http::STATUS_OK), $format);
		}
		throw new OCSBadRequestException('Error deleting app config values');
	}
}
