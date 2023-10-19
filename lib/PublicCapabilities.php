<?php

declare(strict_types=1);

namespace OCA\AppAPI;

use OCA\AppAPI\AppInfo\Application;

use OCP\App\IAppManager;
use OCP\Capabilities\IPublicCapability;

class PublicCapabilities implements IPublicCapability {
	private IAppManager $appManager;

	public function __construct(
		IAppManager $appManager,
	) {
		$this->appManager = $appManager;
	}

	public function getCapabilities(): array {
		$capabilities = [
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
		];
		return [
			'app_api' => $capabilities,
		];
	}
}
