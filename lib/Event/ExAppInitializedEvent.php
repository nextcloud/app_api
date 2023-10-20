<?php

declare(strict_types=1);

namespace OCA\AppAPI\Event;

use OCP\EventDispatcher\Event;

class ExAppInitializedEvent extends Event {
	private string $appId;

	public function __construct(?string $appId) {
		parent::__construct();
		if (isset($appId)) {
			$this->appId = $appId;
		}
	}

	public function setAppid(string $appId): void {
		$this->appId = $appId;
	}

	public function getAppid(): string {
		return $this->appId;
	}
}
