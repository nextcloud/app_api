<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\AuditLog\AuditLogger;
use OCA\AppAPI\AuditLog\Operation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
class ConfigController extends Controller {
	/** Settings whose value is written to the audit log; any other key could hold a secret, so only its name is */
	private const AUDITED_VALUE_KEYS = ['default_daemon_config', 'init_timeout', 'container_restart_policy', 'docker_api_version'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly AuditLogger $auditLogger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Update the AppAPI admin configuration
	 *
	 * @param array<string, string> $values Configuration key-value pairs to store
	 *
	 * @return DataResponse<Http::STATUS_OK, int, array{}>
	 *
	 * 200: Configuration updated
	 */
	#[NoCSRFRequired]
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (!$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true)) {
				continue;
			}
			if (in_array($key, self::AUDITED_VALUE_KEYS, true)) {
				$this->auditLogger->log(Operation::AdminConfigUpdated, 'AppAPI setting %s was changed to %s', ['key' => $key, 'value' => $value]);
			} else {
				$this->auditLogger->log(Operation::AdminConfigUpdated, 'AppAPI setting %s was changed', ['key' => $key]);
			}
		}
		return new DataResponse(1);
	}
}
