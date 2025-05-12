<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\DaemonConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class DockerImageCleanupJob extends TimedJob
{
	private const DEFAULT_INTERVAL_DAYS = 7; // Default interval in days
	private const SECONDS_IN_DAY = 86400; // Number of seconds in a day

	public function __construct(
		ITimeFactory                         $time,
		private readonly DockerActions       $dockerActions,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly IAppConfig          $appConfig,
		protected LoggerInterface            $logger,
	)
	{
		parent::__construct($time);

		// Get the configured interval in days or use default
		$intervalDays = (int)$this->appConfig->getValueString('app_api', 'docker_cleanup_interval_days', (string)self::DEFAULT_INTERVAL_DAYS);

		// If interval is 0, job is disabled
		if ($intervalDays === 0) {
			$this->setInterval(0);
			return;
		}

		// Convert days to seconds for the job interval
		$this->setInterval($intervalDays * self::SECONDS_IN_DAY);
	}

	protected function run($argument): void
	{
		// Check if cleanup is enabled
		$enabled = $this->appConfig->getValueString('app_api', 'docker_cleanup_enabled', 'yes');
		if ($enabled !== 'yes') {
			$this->logger->debug('Docker image cleanup is disabled');
			return;
		}

		$this->logger->info('Starting Docker image cleanup job');

		try {
			// Get cleanup filters from config
			$filters = [];

			// Handle dangling images filter
			$pruneDangling = $this->appConfig->getValueString('app_api', 'docker_cleanup_dangling', 'yes');
			if ($pruneDangling === 'yes') {
				$filters['dangling'] = true;
			}

			// Handle until timestamp filter
			$pruneUntil = $this->appConfig->getValueString('app_api', 'docker_cleanup_until', '');
			if ($pruneUntil !== '') {
				$filters['until'] = $pruneUntil;
			}

			// Handle label filters
			$pruneLabels = $this->appConfig->getValueString('app_api', 'docker_cleanup_labels', '');
			if ($pruneLabels !== '') {
				$labels = json_decode($pruneLabels, true);
				if (is_array($labels)) {
					$filters['label'] = $labels;
				}
			}

			$defaultDaemonConfigName = $this->appConfig->getValueString('app_api', 'default_daemon_config', lazy: true);
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($defaultDaemonConfigName);

			$dockerUrl = $this->dockerActions->buildDockerUrl($daemonConfig);
			$this->dockerActions->initGuzzleClient($daemonConfig);

			$result = $this->dockerActions->pruneImages($dockerUrl, $filters);

			if (empty($result['imagesDeleted'])) {
				$this->logger->info(sprintf('No unused Docker images found for daemon %s', $daemonConfig->getName()));
				return;
			}

			$this->logger->info(
				sprintf(
					'Successfully pruned %d Docker images from daemon %s, reclaimed %d bytes',
					count($result['imagesDeleted']),
					$daemonConfig->getName(),
					$result['spaceReclaimed']
				)
			);

		} catch (\Exception $e) {
			$this->logger->error('Error during Docker image cleanup: ' . $e->getMessage());
		}
	}

}
