<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\Service\UI\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Settings\Events\DeclarativeSettingsRegisterFormEvent;

/**
 * @template-implements IEventListener<Event>
 */
class RegisterDeclarativeSettingsListener implements IEventListener {
	public function __construct(
		private readonly SettingsService $service,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof DeclarativeSettingsRegisterFormEvent) {
			return;
		}

		foreach ($this->service->getRegisteredForms() as $form) {
			$event->registerSchema($form->getAppid(), $form->getScheme());
		}
	}
}
