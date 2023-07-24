<?php

declare(strict_types=1);

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
	abstract public function getAcceptsDeployId(): string;

	/**
	 * Deploy ExApp to the target daemon
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return mixed
	 */
	abstract public function deployExApp(DaemonConfig $daemonConfig, array $params = []): mixed;

	/**
	 * Load ExApp information from the target daemon.
	 *
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array required data for ExApp registration
	 */
	abstract public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array;

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
	abstract public function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string;
}
