<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Service\AppAPIService;

/**
 * Manual deploy actions for development.
 */
class ManualActions implements IDeployActions {
	private AppAPIService $service;

	public function __construct(
		AppAPIService $service,
	) {
		$this->service = $service;
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

	public function buildDeployEnvs(array $params, array $envOptions, array $deployConfig): array {
		// Not implemented. Deploy is done manually.
		return [];
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
			'scopes' => $jsonInfo['scopes'],
		];
	}

	public function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string {
		$jsonInfo = json_decode($params['json-info'], true);
		return $jsonInfo['host'];
	}

	public function healthcheck(array $jsonInfo): bool {
		return $this->service->heartbeatExApp([
			'protocol' => $jsonInfo['protocol'],
			'host' => $jsonInfo['host'],
			'port' => $jsonInfo['port'],
		]);
	}
}
