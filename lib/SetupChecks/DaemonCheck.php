<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\SetupChecks;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Log\LoggerInterface;

readonly class DaemonCheck implements ISetupCheck {
	public function __construct(
		private IL10N $l10n,
		private IConfig $config,
		private IAppConfig $appConfig,
		private DockerActions $dockerActions,
		private LoggerInterface $logger,
		private DaemonConfigService $daemonConfigService,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('AppAPI deploy daemon');
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getDefaultDaemonConfig(): ?DaemonConfig {
		$defaultDaemonConfigName = $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', lazy: true);
		if ($defaultDaemonConfigName === '') {
			return null;
		}

		return $this->daemonConfigService->getDaemonConfigByName($defaultDaemonConfigName);
	}

	public function run(): SetupResult {
		$serverVer = explode('.', $this->config->getSystemValueString('version', 'latest'))[0];

		$daemonConfig = $this->getDefaultDaemonConfig();
		if ($daemonConfig === null) {
			return SetupResult::info(
				$this->l10n->t('AppAPI default deploy daemon is not set. Please register a default deploy daemon in the settings to install External Apps (Ex-Apps).'),
				"https://docs.nextcloud.com/server/$serverVer/admin_manual/exapps_management/AppAPIAndExternalApps.html#setup-deploy-daemon",
			);
		}

		$this->dockerActions->initGuzzleClient($daemonConfig);
		$daemonConfigAccessible = $this->dockerActions->ping($this->dockerActions->buildDockerUrl($daemonConfig));
		if (!$daemonConfigAccessible) {
			$this->logger->error(sprintf('Deploy daemon "%s" is not accessible by Nextcloud. Please check its configuration', $daemonConfig->getName()));
			return SetupResult::error(
				$this->l10n->t('AppAPI default deploy daemon "%s" is not accessible. Please check the daemon configuration.', ['daemon' => $daemonConfig->getName()]),
				"https://docs.nextcloud.com/server/$serverVer/admin_manual/exapps_management/AppAPIAndExternalApps.html#setup-deploy-daemon",
			);
		}

		if (!boolval($daemonConfig->getDeployConfig()['harp'] ?? false)) {
			return SetupResult::warning(
				$this->l10n->t('The AppAPI default deploy daemon is not using HaRP. Please consider switching to HaRP for better performance.'),
				// todo: update link
				'https://github.com/nextcloud/HaRP/',
			);
		}

		// HaRP is accessible since ping is already successful
		return SetupResult::success();
	}
}
