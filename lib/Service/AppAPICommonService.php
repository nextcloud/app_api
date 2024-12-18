<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IRequest;

class AppAPICommonService {

	public function __construct(
		private readonly IAppManager             $appManager,
	) {
	}

	public function buildAppAPIAuthHeaders(?IRequest $request, ?string $userId, string $appId, string $appVersion, string $appSecret): array {
		return [
			'AA-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
			'EX-APP-ID' => $appId,
			'EX-APP-VERSION' => $appVersion,
			'AUTHORIZATION-APP-API' => base64_encode($userId . ':' . $appSecret),
			'AA-REQUEST-ID' => $request instanceof IRequest ? $request->getId() : 'CLI',
		];
	}

	public function buildExAppHost(array $deployConfig): string {
		if (isset($deployConfig['additional_options']['OVERRIDE_APP_HOST'])) {
			return $deployConfig['additional_options']['OVERRIDE_APP_HOST'];
		}
		if (isset($deployConfig['net'])) {
			if ($deployConfig['net'] === 'host') {
				return '127.0.0.1';  # ExApp using this host network, it is visible for Nextcloud on loop-back adapter
			}
			return '0.0.0.0';
		}
		return '127.0.0.1';  # fallback to loop-back adapter
	}
}
