<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Settings;

use OCA\AppEcosystemV2\AppInfo\Application;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	/** @var IConfig */
	private $config;

	/** @var IInitialState */
	private $initialStateService;

	public function __construct(
		IConfig $config,
		IInitialState $initialStateService
	) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		// TODO: Add needed config values here (registered apps, etc.)
		$adminConfig = [
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'app-ecosystem-v2';
	}

	public function getPriority(): int {
		return 10;
	}
}
