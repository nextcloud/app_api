<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Settings;

use OCA\AppEcosystemV2\AppInfo\Application;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	/** @var IL10N */
	private $l;

	/** @var IURLGenerator */
	private $urlGenerator;

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
		return 'app_ecosystem_v2';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('App Ecosystem V2');
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
