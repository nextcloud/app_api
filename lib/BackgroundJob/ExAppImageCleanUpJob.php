<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\DeployActions\DockerActions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class ExAppImageCleanUpJob extends TimedJob {
	private const everyMinuteInterval = 60;
	private int $lastTimeRun = 0;
	public function __construct(
		ITimeFactory                   			$time,
		private readonly LoggerInterface    	$logger,
		private readonly DaemonConfigService    $daemonConfigService,
		private readonly DockerActions          $dockerActions,
		private readonly IJobList 				$jobList,
		private readonly IAppConfig    			$appConfig,
	) {
		parent::__construct($time);

		try {
			$timeoutDaysSetting = intval($this->appConfig->getValueString(Application::APP_ID, 'cleanup_interval', '7', lazy: true));		
			if ($timeoutDaysSetting !== 0){
				$this->setInterval($timeoutDaysSetting * 24 * 60 * 60);
			}
			else{
				$this->setInterval(self::everyMinuteInterval);
			}
		} catch (Exception) {
		}		

		
	}

	protected function run($argument): void {
		foreach ($this->jobList->countByClass() as $jobClass) {
			if ($jobClass['class'] == "OCA\AppAPI\BackgroundJob\ExAppImageCleanUpJob"){
				foreach ($this->jobList->getJobsIterator($jobClass['class'], null, 0) as $job ){
					$lastTimeRun = $job->getLastRun();
				}
			}
		}

		try {
			$timeoutDaysSetting = intval($this->appConfig->getValueString(Application::APP_ID, 'cleanup_interval', '7', lazy: true));		
			if ($timeoutDaysSetting !== 0){
				$allConfig = $this->daemonConfigService->getRegisteredDaemonConfigs();
				foreach ($allConfig as $config) {
					$this->dockerActions->initGuzzleClient($config);	
					$result = $this->dockerActions->deleteUnusedImages($config->getHost());
					$this->logger->info("Successfully deleted unused docker ExApp images. Total space reclaimed {$result['SpaceReclaimed']}",['app' => Application::APP_ID]);		
					$this->setInterval($timeoutDaysSetting * 24 * 60 * 60);				
				}
			}
			else{}
		} catch (Exception) {
		}
	}
}