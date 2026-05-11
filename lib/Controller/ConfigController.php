<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Update the AppAPI admin configuration
	 *
	 * @param array<string, bool|int|string> $values Configuration key-value pairs to store
	 *
	 * @return DataResponse<Http::STATUS_OK, int, array{}>
	 *
	 * 200: Configuration updated
	 */
	#[NoCSRFRequired]
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			// The image-cleanup settings are stored typed (bool/int) so the read side can use
			// getValueBool/getValueInt. Coerce by known key, not by incoming value type: typing
			// by value would let a single API call flip the stored type of a legacy string
			// setting (e.g. init_timeout sent as int) and make every later typed read of it
			// throw AppConfigTypeConflictException.
			if ($key === Application::CONF_IMAGE_CLEANUP_ENABLED) {
				$enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				if ($enabled === null) {
					continue; // unrecognized input must not silently flip the switch
				}
				$this->appConfig->setValueBool(Application::APP_ID, $key, $enabled, lazy: true);
			} elseif ($key === Application::CONF_IMAGE_CLEANUP_GRACE_HOURS) {
				if (!is_numeric($value)) {
					continue;
				}
				$hours = max(0, min(Application::MAX_IMAGE_CLEANUP_GRACE_HOURS, (int)$value));
				$this->appConfig->setValueInt(Application::APP_ID, $key, $hours, lazy: true);
			} else {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$value, lazy: true);
			}
		}
		return new DataResponse(1);
	}
}
