<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\Db\DaemonConfig;

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
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return mixed
	 */
	public function deployExApp(DaemonConfig $daemonConfig, array $params = []): mixed;

	/**
	 * Update existing deployed ExApp on target daemon
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return mixed
	 */
	public function updateExApp(DaemonConfig $daemonConfig, array $params = []): mixed;

	/**
	 * Build required info for ExApp deployment
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param \SimpleXMLElement $infoXml
	 * @param array $params
	 *
	 * @return mixed
	 */
	public function buildDeployParams(DaemonConfig $daemonConfig, \SimpleXMLElement $infoXml, array $params = []): mixed;

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
	 * Load ExApp information from the target daemon.
	 *
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array required data for ExApp registration
	 */
	public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array;

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
