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


use OCP\IRequest;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\ExAppConfigService;

class AppConfigController extends OCSController {
	private ExAppConfigService $exAppConfigService;

	public function __construct(
		IRequest $request,
		ExAppConfigService $exAppConfigService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->exAppConfigService = $exAppConfigService;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param mixed $configValue
	 * @param string $format
	 *
	 * @return Response
	 */
	public function setAppConfigValue(string $appId, string $configKey, mixed $configValue, string $format = 'json'): Response {
		$result = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $configValue);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'setAppConfigValue' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getAppConfigValue(string $appId, string $configKey, string $format = 'json'): Response {
		$appConfigEx = $this->exAppConfigService->getAppConfigValue($appId, $configKey);
		return $this->buildResponse(new DataResponse([
			'success' => $appConfigEx !== null,
			'appConfigEx' => $appConfigEx,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getAppConfigKeys(string $appId, string $format = 'json'): Response {
		$appConfigKeys = $this->exAppConfigService->getAppConfigKeys($appId);
		return $this->buildResponse(new DataResponse([
			'success' => $appConfigKeys !== null,
			'appConfigKeys' => $appConfigKeys,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $configKey
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteAppConfigValue(string $appId, string $configKey, string $format = 'json'): Response {
		$result = $this->exAppConfigService->deleteAppConfigValue($appId, $configKey);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'deletedAppConfigValue' => $result,
		], Http::STATUS_OK), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteAppConfigValues(string $appId, string $format = 'json'): Response {
		$result = $this->exAppConfigService->deleteAppConfigValues($appId);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null && $result > 0,
			'deletedAppConfigValuesCount' => $result,
		], Http::STATUS_OK), $format);
	}
}
