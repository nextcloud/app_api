<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\SetupChecks;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

readonly class DockerSocketProxyCheck implements ISetupCheck {
	public function __construct(
		private IL10N $l10n,
		private DaemonConfigService $daemonConfigService,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('AppAPI Docker Socket Proxy daemons');
	}

	public function getCategory(): string {
		return 'system';
	}

	public function run(): SetupResult {
		$dockerSocketProxyDaemons = array_map(
			static fn (DaemonConfig $daemonConfig): string => $daemonConfig->getName(),
			array_filter($this->daemonConfigService->getRegisteredDaemonConfigs(), DockerActions::isDockerSocketProxy(...)),
		);
		if ($dockerSocketProxyDaemons === []) {
			return SetupResult::success();
		}
		return SetupResult::error(
			$this->l10n->t('Docker Socket Proxy daemons are no longer supported: %s. Uninstall their ExApps keeping the data, install them on a HaRP daemon on the same Docker host, then remove these daemons.', [implode(', ', $dockerSocketProxyDaemons)]),
			'https://github.com/nextcloud/HaRP#migrating-exapps-from-docker-socket-proxy-to-harp',
		);
	}
}
