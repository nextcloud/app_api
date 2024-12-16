<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Db\ExAppConfig;
use OCA\AppAPI\Service\ExAppConfigService;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class AppConfigController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                            $request,
		private readonly ExAppConfigService $exAppConfigService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setAppConfigValue(string $configKey, mixed $configValue, ?int $sensitive = null): DataResponse {
		if ($configKey === '') {
			throw new OCSBadRequestException('Config key cannot be empty');
		}
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $configValue, $sensitive);
		if ($result instanceof ExAppConfig) {
			return new DataResponse($result, Http::STATUS_OK);
		}
		throw new OCSBadRequestException('Error setting app config value');
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getAppConfigValues(array $configKeys): DataResponse {
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppConfigService->getAppConfigValues($appId, $configKeys);
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @throws OCSBadRequestException
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteAppConfigValues(array $configKeys): DataResponse {
		$appId = $this->request->getHeader('EX-APP-ID');
		$result = $this->exAppConfigService->deleteAppConfigValues($configKeys, $appId);
		if ($result === -1) {
			throw new OCSBadRequestException('Error deleting app config values');
		}
		if ($result === 0) {
			throw new OCSNotFoundException('No appconfig_ex values deleted');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
