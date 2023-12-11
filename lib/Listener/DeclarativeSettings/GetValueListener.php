<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use Exception;
use OCA\AppAPI\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Settings\GetDeclarativeSettingsValueEvent;

/**
 * @template-implements IEventListener<GetDeclarativeSettingsValueEvent>
 */
class GetValueListener implements IEventListener {
	public function __construct(
		private readonly IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GetDeclarativeSettingsValueEvent) {
			return;
		}

		if ($event->getApp() !== Application::APP_ID) {
			return;
		}

		try {
			$value = $this->config->getUserValue($event->getUser()->getUID(), $event->getApp(), $event->getFieldId());
			$event->setValue($value);
		} catch (Exception) {
		}
	}
}
