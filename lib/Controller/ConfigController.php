<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class ConfigController extends Controller {

	public function __construct(
		string                      $appName,
		IRequest                    $request,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true);
		}
		return new DataResponse(1);
	}
}
