<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\Db\DaemonConfig;

/**
 * Manual deploy actions for development.
 */
class ManualActions implements IDeployActions {

	public function __construct() {
	}

	public function getAcceptsDeployId(): string {
		return 'manual-install';
	}

	public function deployExApp(DaemonConfig $daemonConfig, array $params = []): mixed {
		// Not implemented. Deploy is done manually.
		return null;
	}

	public function updateExApp(DaemonConfig $daemonConfig, array $params = []): mixed {
		// Not implemented. Update is done manually.
		return null;
	}

	public function buildDeployParams(DaemonConfig $daemonConfig, $infoXml, array $params = []): mixed {
		// Not implemented. Deploy is done manually.
		return null;
	}

	public function buildDeployEnvs(array $params, array $deployConfig): array {
		// Not implemented. Deploy is done manually.
		return [];
	}

	public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array {
		$jsonInfo = json_decode($params['json-info'], true);
		return [
			'appid' => $jsonInfo['appid'],
			'version' => $jsonInfo['version'],
			'name' => $jsonInfo['name'],
			'port' => $jsonInfo['port'],
			'secret' => $jsonInfo['secret'],
			'system_app' => $jsonInfo['system_app'],
			'scopes' => $jsonInfo['scopes'],
		];
	}

	public function resolveExAppUrl(
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth
	): string {
		$auth = [];
		return sprintf('%s://%s:%s', $protocol, $host, $port);
	}
}
