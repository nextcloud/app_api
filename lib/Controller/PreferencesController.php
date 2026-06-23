<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Db\ExAppPreference;
use OCA\AppAPI\ResponseDefinitions;
use OCA\AppAPI\Service\ExAppPreferenceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-import-type AppAPIExAppPreference from ResponseDefinitions
 * @psalm-import-type AppAPIExAppConfigValue from ResponseDefinitions
 */
class PreferencesController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ExAppPreferenceService $exAppPreferenceService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Set a preference value for the current user and the calling ExApp
	 *
	 * @param string $configKey Preference key
	 * @param mixed $configValue Preference value
	 * @param ?int $sensitive Whether the value is sensitive and should be stored encrypted (1) or not (0)
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIExAppPreference, array{}>
	 * @throws OCSBadRequestException Config key is empty or the value could not be set
	 *
	 * 200: Preference value set
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setUserConfigValue(string $configKey, mixed $configValue, ?int $sensitive = null): DataResponse {
		if ($configKey === '') {
			throw new OCSBadRequestException('Config key cannot be empty');
		}
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('ex-app-id');
		$result = $this->exAppPreferenceService->setUserConfigValue($userId, $appId, $configKey, $configValue, $sensitive);
		if ($result instanceof ExAppPreference) {
			return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
		}
		throw new OCSBadRequestException('Failed to set user config value');
	}

	/**
	 * Get preference values of the current user for the calling ExApp
	 *
	 * @param list<string> $configKeys Preference keys to retrieve
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AppAPIExAppConfigValue>, array{}>
	 *
	 * 200: Preference values returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getUserConfigValues(array $configKeys): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('ex-app-id');
		$result = $this->exAppPreferenceService->getUserConfigValues($userId, $appId, $configKeys);
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * Delete preference values of the current user for the calling ExApp
	 *
	 * @param list<string> $configKeys Preference keys to delete
	 *
	 * @return DataResponse<Http::STATUS_OK, int, array{}>
	 * @throws OCSBadRequestException Failed to delete the preference values
	 * @throws OCSNotFoundException No matching preference values were found
	 *
	 * 200: Number of deleted preference values returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteUserConfigValues(array $configKeys): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('ex-app-id');
		$result = $this->exAppPreferenceService->deleteUserConfigValues($configKeys, $userId, $appId);
		if ($result === -1) {
			throw new OCSBadRequestException('Failed to delete user config values');
		}
		if ($result === 0) {
			throw new OCSNotFoundException('No user config values deleted');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
