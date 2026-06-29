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
use OCA\AppAPI\ResponseDefinitions;
use OCA\AppAPI\Service\ExAppConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type AppAPIExAppConfig from ResponseDefinitions
 * @psalm-import-type AppAPIExAppConfigValue from ResponseDefinitions
 */
class AppConfigController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly ExAppConfigService $exAppConfigService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Set a configuration value for the calling ExApp
	 *
	 * @param string $configKey Configuration key
	 * @param mixed $configValue Configuration value
	 * @param ?int $sensitive Whether the value is sensitive and should be stored encrypted (1) or not (0)
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIExAppConfig, array{}>
	 * @throws OCSBadRequestException Config key is empty or the value could not be set
	 *
	 * 200: Config value set
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setAppConfigValue(string $configKey, mixed $configValue, ?int $sensitive = null): DataResponse {
		if ($configKey === '') {
			throw new OCSBadRequestException('Config key cannot be empty');
		}
		$appId = $this->request->getHeader('ex-app-id');
		$result = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $configValue, $sensitive);
		if ($result instanceof ExAppConfig) {
			return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
		}
		throw new OCSBadRequestException('Error setting app config value');
	}

	/**
	 * Get configuration values of the calling ExApp
	 *
	 * @param list<string> $configKeys Configuration keys to retrieve
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AppAPIExAppConfigValue>, array{}>
	 *
	 * 200: Config values returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getAppConfigValues(array $configKeys): DataResponse {
		$appId = $this->request->getHeader('ex-app-id');
		$result = $this->exAppConfigService->getAppConfigValues($appId, $configKeys);
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * Delete configuration values of the calling ExApp
	 *
	 * @param list<string> $configKeys Configuration keys to delete
	 *
	 * @return DataResponse<Http::STATUS_OK, int, array{}>
	 * @throws OCSBadRequestException Failed to delete the config values
	 * @throws OCSNotFoundException No matching config values were found
	 *
	 * 200: Number of deleted config values returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteAppConfigValues(array $configKeys): DataResponse {
		$appId = $this->request->getHeader('ex-app-id');
		$result = $this->exAppConfigService->deleteAppConfigValues($configKeys, $appId);
		if ($result === -1) {
			throw new OCSBadRequestException('Error deleting app config values');
		}
		if ($result === 0) {
			throw new OCSNotFoundException('No app config values deleted');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
