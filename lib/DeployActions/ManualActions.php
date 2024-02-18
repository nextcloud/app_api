<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeployActions;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\ExAppService;

/**
 * Manual deploy actions for development.
 */
class ManualActions implements IDeployActions {

	public function __construct(
		private readonly ExAppService		 $exAppService,
	) {
	}

	public function getAcceptsDeployId(): string {
		return 'manual-install';
	}

	public function deployExApp(ExApp $exApp, DaemonConfig $daemonConfig, array $params = []): string {
		// Not implemented. Deploy is done manually.
		$this->exAppService->setAppDeployProgress($exApp, 0);
		$this->exAppService->setAppDeployProgress($exApp, 100);
		return '';
	}

	public function buildDeployParams(DaemonConfig $daemonConfig, array $appInfo, array $params = []): mixed {
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
