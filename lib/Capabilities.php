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

namespace OCA\AppEcosystemV2;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\IPublicCapability;
use OCP\IConfig;

class Capabilities implements IPublicCapability {
	private IConfig $config;
	private IAppManager $appManager;

	public function __construct(
		IConfig $config,
		IAppManager $appManager
	) {
		$this->config = $config;
		$this->appManager = $appManager;
	}

	public function getCapabilities(): array {
		$capabilities = [
			'nc-log-level' => $this->config->getSystemValue('loglevel', 2),
			'app-ecosystem-version' => $this->appManager->getAppVersion(Application::APP_ID),
		];
		return [
			'app_ecosystem_v2' => $capabilities,
		];
	}
}
