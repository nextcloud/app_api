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

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Db\ExAppPreference;
use OCA\AppEcosystemV2\Service\ExAppPreferenceService;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
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
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setUserConfigValue(string $configKey, mixed $configValue, string $format = 'json'): Response {
		if ($configKey === '') {
			throw new OCSBadRequestException('Config key cannot be empty');
		}
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->setUserConfigValue($userId, $appId, $configKey, $configValue);
		if ($result instanceof ExAppPreference) {
			return $this->buildResponse(new DataResponse(1, Http::STATUS_OK), $format);
		}
		throw new OCSBadRequestException('Failed to set user config value');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $configKeys
	 * @param string $format
	 *
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getUserConfigValues(array $configKeys, string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->getUserConfigValues($userId, $appId, $configKeys);
		return $this->buildResponse(new DataResponse($result, Http::STATUS_OK), $format);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $configKeys
	 * @param string $format
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteUserConfigValues(array $configKeys, string $format = 'json'): Response {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->deleteUserConfigValues($configKeys, $userId, $appId);
		if ($result !== -1) {
			return $this->buildResponse(new DataResponse($result, Http::STATUS_OK), $format);
		}
		throw new OCSBadRequestException('Failed to delete user config values');
	}
}
