<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use Exception;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeclarativeSettings\DeclarativeSettingsForm;
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
		private DeclarativeSettingsForm $form,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GetDeclarativeSettingsValueEvent) {
			return;
		}

		// TODO: Get list of ExApps that have registered declarative settings
		if ($event->getApp() !== Application::APP_ID && $event->getApp() !== Application::APP_ID . '_dup') {
			return;
		}

		try {
			foreach ($this->form->getSchema()['fields'] as $field) {
				if (isset($field['default']) && $field['id'] === $event->getFieldId()
					|| isset($field['default']) && $field['id'] . '_dup' === $event->getFieldId()) {
					if (is_array($field['default']) || is_numeric($field['default'])) {
						$default = json_encode($field['default']);
					} else {
						$default = $field['default'];
					}
				}
			}
			$value = $this->config->getUserValue($event->getUser()->getUID(), $event->getApp(), $event->getFieldId(), $default ?? null);
			$event->setValue($value);
		} catch (Exception) {
		}
	}
}
