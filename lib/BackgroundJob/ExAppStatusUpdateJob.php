<?php

declare(strict_types=1);

namespace OCA\AppAPI\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class ExAppStatusUpdateJob extends TimedJob {
	private const every10MinutesInterval = 60 * 10;

	public function __construct(ITimeFactory $time) {
		parent::__construct($time);

		$this->setInterval(self::every10MinutesInterval);
	}

	protected function run($argument): void {
		// TODO: Go through registered ExApps, send status update requests, disable ExApps that are not responding
		// TODO: disable ExApps with exceeded last_response_time
	}
}
