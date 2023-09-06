<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppApiAuth;
use OCA\AppAPI\Db\ExAppPreference;
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
	 *
	 * @throws OCSBadRequestException
	 * @return DataResponse
	 */
	#[AppApiAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setUserConfigValue(string $configKey, mixed $configValue): DataResponse {
		if ($configKey === '') {
			throw new OCSBadRequestException('Config key cannot be empty');
		}
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->setUserConfigValue($userId, $appId, $configKey, $configValue);
		if ($result instanceof ExAppPreference) {
			return new DataResponse(1, Http::STATUS_OK);
		}
		throw new OCSBadRequestException('Failed to set user config value');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $configKeys
	 *
	 * @return DataResponse
	 */
	#[AppApiAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getUserConfigValues(array $configKeys): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->getUserConfigValues($userId, $appId, $configKeys);
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $configKeys
	 *
	 * @throws OCSBadRequestException
	 * @throws OCSNotFoundException
	 * @return DataResponse
	 */
	#[AppApiAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteUserConfigValues(array $configKeys): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppPreferenceService->deleteUserConfigValues($configKeys, $userId, $appId);
		if ($result === -1) {
			throw new OCSBadRequestException('Failed to delete user config values');
		}
		if ($result === 0) {
			throw new OCSNotFoundException('No preferences_ex values deleted');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
