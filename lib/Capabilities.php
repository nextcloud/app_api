<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI;

use OCA\AppAPI\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IConfig;

class Capabilities implements ICapability {

	public function __construct(
		private readonly IConfig     $config,
		private readonly IAppManager $appManager,
	) {
	}

	public function getCapabilities(): array {
		$capabilities = [
			'loglevel' => intval($this->config->getSystemValue('loglevel', 2)),
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
		];
		return [
			'app_api' => $capabilities,
		];
	}
}
