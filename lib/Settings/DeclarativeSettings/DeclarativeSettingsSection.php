<?php

declare(strict_types=1);

namespace OCA\AppAPI\Settings\DeclarativeSettings;

use OCA\AppAPI\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class DeclarativeSettingsSection implements IIconSection {

	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getID(): string {
		return 'ex_apps_section';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('ExApps declarative');
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority(): int {
		return 1;
	}

	/**
	 * @inheritDoc
	 */
	public function getIcon(): ?string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}

}
