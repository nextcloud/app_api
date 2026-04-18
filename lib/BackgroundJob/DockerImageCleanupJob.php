<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class DockerImageCleanupJob extends TimedJob {
	private const SECONDS_PER_DAY = 86400;

	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly DockerActions $dockerActions,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$intervalDays = $this->appConfig->getValueInt(
			Application::APP_ID, Application::CONF_IMAGE_CLEANUP_INTERVAL_DAYS, 7, lazy: true
		);
		$this->setInterval(max($intervalDays, 1) * self::SECONDS_PER_DAY);
	}

	protected function run($argument): void {
		if ($this->isCleanupDisabled()) {
			return;
		}

		$this->pruneImagesOnAllDaemons();
	}

	private function isCleanupDisabled(): bool {
		$intervalDays = $this->appConfig->getValueInt(
			Application::APP_ID, Application::CONF_IMAGE_CLEANUP_INTERVAL_DAYS, 7, lazy: true
		);
		return $intervalDays === 0;
	}

	private function pruneImagesOnAllDaemons(): void {
		$daemons = $this->daemonConfigService->getRegisteredDaemonConfigs();
		foreach ($daemons as $daemon) {
			if (!$this->supportsPrune($daemon)) {
				continue;
			}

			try {
				$this->dockerActions->initGuzzleClient($daemon);
				$dockerUrl = $this->dockerActions->buildDockerUrl($daemon);
				$result = $this->dockerActions->pruneImages($dockerUrl, ['dangling' => ['true']]);
				if (isset($result['error'])) {
					$this->logger->error(sprintf(
						'Image prune failed for daemon "%s": %s',
						$daemon->getName(), $result['error']
					));
				}
			} catch (\Exception $e) {
				$this->logger->error(sprintf(
					'Exception during image prune for daemon "%s": %s',
					$daemon->getName(), $e->getMessage()
				));
			}
		}
	}

	private function supportsPrune(DaemonConfig $daemon): bool {
		if ($daemon->getAcceptsDeployId() !== DockerActions::DEPLOY_ID) {
			return false;
		}

		// HaRP daemons are Docker-type but route through a proxy that doesn't
		// support the /images/prune endpoint yet. Skip until upstream HaRP adds it.
		if (!empty($daemon->getDeployConfig()['harp'])) {
			$this->logger->debug(sprintf(
				'Skipping image prune for HaRP daemon "%s" (not yet supported)',
				$daemon->getName()
			));
			return false;
		}

		return true;
	}
}
