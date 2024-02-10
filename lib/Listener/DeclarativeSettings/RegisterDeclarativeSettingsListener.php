<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\Service\ExAppSettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Settings\RegisterDeclarativeSettingsFormEvent;

/**
 * @template-implements IEventListener<RegisterDeclarativeSettingsFormEvent>
 */
class RegisterDeclarativeSettingsListener implements IEventListener {
	public function __construct(
		private readonly ExAppSettingsService $service,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterDeclarativeSettingsFormEvent) {
			return;
		}

		foreach ($this->service->getRegisteredForms() as $form) {
			$event->registerSchema($form->getAppid(), $form->getScheme());
		}
	}
}
