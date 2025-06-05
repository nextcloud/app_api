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
use OCP\IAppConfig;

class ExAppInitStatusCheckJob extends TimedJob {
	private const everyMinuteInterval = 60;

	public function __construct(
		ITimeFactory                   $time,
		private readonly ExAppMapper   $mapper,
		private readonly AppAPIService $service,
		private readonly IAppConfig    $appConfig,
	) {
		parent::__construct($time);

		$this->setInterval(self::everyMinuteInterval);
	}

	protected function run($argument): void {
		// Iterate over all ExApp and check for status.init_start_time if it is older than init_timeout minutes
		// set status.progress=0 and status.error message with timeout error
		try {
			$exApps = $this->mapper->findAll();
			$initTimeoutMinutesSetting = intval($this->appConfig->getValueString(Application::APP_ID, 'init_timeout', '40', lazy: true));
			foreach ($exApps as $exApp) {
				$status = $exApp->getStatus();
				if (isset($status['init']) && $status['init'] !== 100) {
					if (!isset($status['init_start_time'])) {
						continue;
					}
					if ($exApp->getAppid() === Application::TEST_DEPLOY_APPID) {
						$initTimeoutSeconds = 30;  // Check for smaller timeout(half of minute) for test deploy app
					} else {
						$initTimeoutSeconds = (int) ($initTimeoutMinutesSetting * 60);
					}
					if ((time() >= ($status['init_start_time'] + $initTimeoutSeconds)) && (empty($status['error']))) {
						$this->service->setAppInitProgress(
							$exApp, 0, sprintf('ExApp %s initialization timed out (%sm)', $exApp->getAppid(), $initTimeoutSeconds)
						);
					}
				}
			}
		} catch (Exception) {
		}
	}
}
