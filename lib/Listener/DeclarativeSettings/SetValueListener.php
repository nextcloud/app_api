<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Settings\SetDeclarativeSettingsValueEvent;

/**
 * @template-implements IEventListener<SetDeclarativeSettingsValueEvent>
 */
class SetValueListener implements IEventListener {
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof SetDeclarativeSettingsValueEvent) {
			return;
		}

		if ($event->getApp() !== Application::APP_ID) {
			return;
		}

		// TODO: Retrieve form data to check where to store the value (admin or personal)
		// TODO: We need identifier which for is used

		$this->config->setUserValue($event->getUser()->getUID(), $event->getApp(), $event->getFieldId(), $event->getValue());
	}
}
