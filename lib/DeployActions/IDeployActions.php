<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\Db\DaemonConfig;

/**
 * Base interface for AppEcosystemV2 ExApp deploy actions
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
	 * @param array $envOptions
	 * @param array $deployConfig
	 *
	 * @return mixed
	 */
	public function buildDeployEnvs(array $params, array $envOptions, array $deployConfig): array;

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
	 * Resolve ExApp host depending on daemon configuration.
	 * Algorithm can be different for each deploy action (type).
	 *
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return string
	 */
	public function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string;
}
