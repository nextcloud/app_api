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

namespace OCA\AppEcosystemV2\DeployActions;

use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCA\AppEcosystemV2\Deploy\DeployActions;

/**
 * Manual deploy actions for development.
 */
class ManualActions extends DeployActions {
	public function getAcceptsDeployId(): string {
		return 'manual-install';
	}

	public function deployExApp(DaemonConfig $daemonConfig, array $params = []): mixed {
		// Not implemented. Deploy is done manually.
		return null;
	}

	public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array {
		$jsonInfo = json_decode($params['json-info'], true);
		return [
			'appid' => $jsonInfo['appid'],
			'version' => $jsonInfo['version'],
			'name' => $jsonInfo['name'],
			'protocol' => $jsonInfo['protocol'],
			'port' => $jsonInfo['port'],
			'host' => $jsonInfo['host'],
			'secret' => $jsonInfo['secret'],
			'system_app' => $jsonInfo['system_app'],
		];
	}

	public function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string {
		$jsonInfo = json_decode($params['json-info'], true);
		return $jsonInfo['host'];
	}
}
