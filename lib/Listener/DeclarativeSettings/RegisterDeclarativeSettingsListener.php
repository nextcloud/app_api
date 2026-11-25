<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\Service\UI\SettingsFormValidator;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Settings\Events\DeclarativeSettingsRegisterFormEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
readonly class RegisterDeclarativeSettingsListener implements IEventListener {
	public function __construct(
		private SettingsService $service,
		private LoggerInterface $logger,
		private SettingsFormValidator $validator,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof DeclarativeSettingsRegisterFormEvent) {
			return;
		}

		foreach ($this->service->getRegisteredForms() as $form) {
			try {
				$schema = $form->getScheme();
				$reason = $this->validator->validate($schema);
				if ($reason === null) {
					$event->registerSchema($form->getAppid(), $schema);
					continue;
				}
			} catch (\Exception|\TypeError $e) {
				$reason = $e->getMessage();
			}
			$this->logger->warning('Skipping settings form {formId} from ExApp {appId}: {reason}', [
				'formId' => $form->getFormid(),
				'appId' => $form->getAppid(),
				'reason' => $reason,
			]);
		}
	}
}
