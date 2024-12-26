<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExAppMapper;
use OCA\AppAPI\Service\AppAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\Exception;
use OCP\IConfig;

class ExAppInitStatusCheckJob extends TimedJob {
	private const everyMinuteInterval = 60;

	public function __construct(
		ITimeFactory                   $time,
		private readonly ExAppMapper   $mapper,
		private readonly AppAPIService $service,
		private readonly IConfig       $config,
	) {
		parent::__construct($time);

		$this->setInterval(self::everyMinuteInterval);
	}

	protected function run($argument): void {
		// Iterate over all ExApp and check for status.init_start_time if it is older than init_timeout minutes
		// set status.progress=0 and status.error message with timeout error
		try {
			$exApps = $this->mapper->findAll();
			$initTimeoutMinutesSetting = intval($this->config->getAppValue(Application::APP_ID, 'init_timeout', '40'));
			foreach ($exApps as $exApp) {
				$status = $exApp->getStatus();
				if (isset($status['init']) && $status['init'] !== 100) {
					if (!isset($status['init_start_time'])) {
						continue;
					}
					if ($exApp->getAppid() === Application::TEST_DEPLOY_APPID) {
						// Check for smaller timeout for test deploy app
						$initTimeoutMinutes = 0.5;
					} else {
						$initTimeoutMinutes = $initTimeoutMinutesSetting;
					}
					if ((time() >= ($status['init_start_time'] + $initTimeoutMinutes * 60)) && (empty($status['error']))) {
						$this->service->setAppInitProgress(
							$exApp, 0, sprintf('ExApp %s initialization timed out (%sm)', $exApp->getAppid(), $initTimeoutMinutes * 60)
						);
					}
				}
			}
		} catch (Exception) {
		}
	}
}
