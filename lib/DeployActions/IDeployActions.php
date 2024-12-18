<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;

/**
 * Base interface for AppAPI ExApp deploy actions
 */
interface IDeployActions {
	/**
	 * Deploy type (action) id name
	 *
	 * @return string
	 */
	public function getAcceptsDeployId(): string;

	/**
	 * Deploy ExApp to the target daemon
	 *
	 * @param ExApp $exApp
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return mixed
	 */
	public function deployExApp(ExApp $exApp, DaemonConfig $daemonConfig, array $params = []): string;

	/**
	 * Build required info for ExApp deployment
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $appInfo
	 *
	 * @return mixed
	 */
	public function buildDeployParams(DaemonConfig $daemonConfig, array $appInfo): mixed;

	/**
	 * Build required deploy environment variables
	 *
	 * @param array $params
	 * @param array $deployConfig
	 *
	 * @return mixed
	 */
	public function buildDeployEnvs(array $params, array $deployConfig): array;

	/**
	 * Resolve ExApp URL(protocol://url:port) depending on the daemon configuration.
	 * Algorithm can be different for each deploy action (type).
	 * "auth" is output and will contain if needed additional authentication data to reach ExApp.
	 *
	 * @param string $appId
	 * @param string $protocol
	 * @param string $host
	 * @param array $deployConfig
	 * @param int $port
	 * @param array $auth
	 * @return string
	 */
	public function resolveExAppUrl(
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth
	): string;
}
