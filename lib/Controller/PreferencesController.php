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

use OCA\AppEcosystemV2\Db\ExAppPreference;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCSController;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\ExAppPreferenceService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class PreferencesController extends OCSController {
	protected $request;
	private IUserSession $userSession;
	private ExAppPreferenceService $exAppPreferenceService;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		ExAppPreferenceService $exAppPreferenceService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->userSession = $userSession;
		$this->exAppPreferenceService = $exAppPreferenceService;
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
	 * @return Response
	 */
	public function setUserConfigValue(string $configKey, mixed $configValue, string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->setUserConfigValue($userId, $appId, $configKey, $configValue);
		if ($result instanceof ExAppPreference) {
			return $this->buildResponse(new DataResponse($result, Http::STATUS_OK), $format);
		}
		return $this->buildResponse(new DataResponse([
			'message' => 'Failed to set user config value',
		], Http::STATUS_INTERNAL_SERVER_ERROR), $format);
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
	public function getUserConfigValue(array $configKeys, string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->getUserConfigValue($userId, $appId, $configKeys);
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
	public function getUserConfigKeys(string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->getUserConfigKeys($userId, $appId);
		return $this->buildResponse(new DataResponse($result), $format);
	}

	/**
	 * @AEAuth
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $configKey
	 * @param string $format
	 *
	 * @return Response
	 */
	public function deleteUserConfigValue(string $configKey, string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->deleteUserConfigValue($userId, $appId, $configKey);
		if ($result) {
			return $this->buildResponse(new DataResponse(1, Http::STATUS_OK), $format);
		}
		return $this->buildResponse(new DataResponse([
			'message' => 'Failed to delete user config value',
		], Http::STATUS_INTERNAL_SERVER_ERROR), $format);
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
	public function deleteUserConfigValues(array $configKeys, string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->deleteUserConfigValues($configKeys, $userId, $appId);
		if ($result) {
			return $this->buildResponse(new DataResponse(1, Http::STATUS_OK), $format);
		}
		return $this->buildResponse(new DataResponse([
			'message' => 'Failed to delete user config values',
		], Http::STATUS_INTERNAL_SERVER_ERROR), $format);
	}
}
