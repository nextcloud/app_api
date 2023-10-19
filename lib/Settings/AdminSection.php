<?php

declare(strict_types=1);

namespace OCA\AppAPI\Settings;

use OCA\AppAPI\AppInfo\Application;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	private IL10N $l;
	private IURLGenerator $urlGenerator;

	public function __construct(
		IURLGenerator $urlGenerator,
		IL10N $l
	) {
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * @inheritDoc
	 */
	public function getID(): string {
		return Application::APP_ID;
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('AppAPI');
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority(): int {
		return 50;
	}

	/**
	 * @inheritDoc
	 */
	public function getIcon(): ?string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}

}
