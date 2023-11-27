<?php

declare(strict_types=1);

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
		ITimeFactory $time,
		private ExAppMapper $mapper,
		private AppAPIService $service,
		private IConfig $config,
	) {
		parent::__construct($time);

		$this->setInterval(self::everyMinuteInterval);
	}

	protected function run($argument): void {
		// Iterate over all ExApp and check for status.init_start_time if it is older than ex_app_init_timeout minutes
		// set status.progress=0 and status.error message with timeout error
		try {
			$exApps = $this->mapper->findAll();
			$initTimeoutMinutes = intval($this->config->getAppValue(Application::APP_ID, 'ex_app_init_timeout', '40'));
			foreach ($exApps as $exApp) {
				$status = json_decode($exApp->getStatus(), true);
				if (!isset($status['init_start_time'])) {
					continue;
				}
				if (($status['init_start_time'] + $initTimeoutMinutes * 60) > time()) {
					$this->service->setAppInitProgress(
						$exApp->getAppId(), 0, sprintf('ExApp %s initialization timed out (%sm)', $exApp->getAppid(), $initTimeoutMinutes * 60)
					);
				}
			}
		} catch (Exception) {
		}
	}
}
