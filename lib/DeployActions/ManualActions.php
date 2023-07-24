<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\DeployActions;

use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCA\AppEcosystemV2\Deploy\AbstractDeployActions;

/**
 * Manual deploy actions for development.
 */
class ManualActions extends AbstractDeployActions {
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
