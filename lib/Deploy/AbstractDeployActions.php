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

namespace OCA\AppEcosystemV2\Deploy;

use OCA\AppEcosystemV2\Db\DaemonConfig;

/**
 * Base class for AppEcosystemV2 ExApp deploy actions
 */
abstract class AbstractDeployActions {
	/**
	 * Deploy type (action) id name
	 *
	 * @return string
	 */
	public abstract function getAcceptsDeployId(): string;

	/**
	 * Deploy ExApp to the target daemon
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return mixed
	 */
	public abstract function deployExApp(DaemonConfig $daemonConfig, array $params = []): mixed;

	/**
	 * Load ExApp information from the target daemon.
	 *
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array required data for ExApp registration
	 */
	public abstract function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array;

	/**
	 * Resolve ExApp host depending on daemon configuration.
	 * Algorithm can be different for each deploy action (type).
	 *
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return string
	 */
	public abstract function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string;
}
