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

use OCP\AppFramework\OCSController;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\ExAppPreferenceService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class PreferencesController extends OCSController {
	private ExAppPreferenceService $exAppPreferenceService;

	public function __construct(
		IRequest $request,
		ExAppPreferenceService $exAppPreferenceService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->exAppPreferenceService = $exAppPreferenceService;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $configKey
	 * @param mixed $configValue
	 * @param string $format
	 *
	 * @return Response
	 */
	public function setUserConfigValue(string $userId, string $appId, string $configKey, mixed $configValue, string $format = 'json'): Response {
		$result = $this->exAppPreferenceService->setUserConfigValue($userId, $appId, $configKey, $configValue);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'userConfigValue' => $result,
		]), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $configKey
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getUserConfigValue(string $userId, string $appId, string $configKey, string $format = 'json'): Response {
		$result = $this->exAppPreferenceService->getUserConfigValue($userId, $appId, $configKey);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'userConfigValue' => $result,
		]), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	public function getUserConfigKeys(string $userId, string $appId, string $format = 'json'): Response {
		$result = $this->exAppPreferenceService->getUserConfigKeys($userId, $appId);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'userConfigKeys' => $result,
		]), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $configKey
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteUserConfigValue(string $userId, string $appId, string $configKey, string $format = 'json'): Response {
		$result = $this->exAppPreferenceService->deleteUserConfigValue($userId, $appId, $configKey);
		return $this->buildResponse(new DataResponse([
			'success' => $result,
		]), $format);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param string $appId
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteUserConfigValues(string $userId, string $appId, string $format = 'json'): Response {
		$result = $this->exAppPreferenceService->deleteUserConfigValues($userId, $appId);
		return $this->buildResponse(new DataResponse([
			'success' => $result !== null,
			'deletedConfigKeys' => $result,
		]), $format);
	}
}
