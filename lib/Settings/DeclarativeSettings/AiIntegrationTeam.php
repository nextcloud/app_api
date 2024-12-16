<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Settings\DeclarativeSettings;

use OCA\AppAPI\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AiIntegrationTeam implements IIconSection {

	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getID(): string {
		return 'ai_integration_team';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('AI Integration Team');
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority(): int {
		return 30;
	}

	/**
	 * @inheritDoc
	 */
	public function getIcon(): ?string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
