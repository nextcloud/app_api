<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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

	public function buildDeployParams(DaemonConfig $daemonConfig, array $appInfo): mixed {
		// Not implemented. Deploy is done manually.
		return null;
	}

	public function buildDeployEnvs(array $params, array $deployConfig): array {
		// Not implemented. Deploy is done manually.
		return [];
	}

	public function resolveExAppUrl(
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth
	): string {
		$auth = [];
		if (isset($deployConfig['additional_options']['OVERRIDE_APP_HOST']) &&
			$deployConfig['additional_options']['OVERRIDE_APP_HOST'] !== ''
		) {
			$wideNetworkAddresses = ['0.0.0.0', '127.0.0.1', '::', '::1'];
			if (!in_array($deployConfig['additional_options']['OVERRIDE_APP_HOST'], $wideNetworkAddresses)) {
				$host = $deployConfig['additional_options']['OVERRIDE_APP_HOST'];
			}
		}
		return sprintf('%s://%s:%s', $protocol, $host, $port);
	}
}
