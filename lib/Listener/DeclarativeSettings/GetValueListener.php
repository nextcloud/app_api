<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\Service\ExAppConfigService;
use OCA\AppAPI\Service\ExAppPreferenceService;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;

/**
 * @template-implements IEventListener<Event>
 */
class GetValueListener implements IEventListener {
	public function __construct(
		private readonly SettingsService        $service,
		private readonly ExAppPreferenceService $preferenceService,
		private readonly ExAppConfigService     $configService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof DeclarativeSettingsGetValueEvent) {
			return;
		}

		$settingsForm = $this->service->getForm($event->getApp(), $event->getFormId());
		if ($settingsForm === null) {
			return;
		}
		$formSchema = $settingsForm->getScheme();
		if ($formSchema['section_type'] === DeclarativeSettingsTypes::SECTION_TYPE_ADMIN) {
			$existingValue = $this->configService->getAppConfig($event->getApp(), $event->getFieldId());
			if (!empty($existingValue)) {
				$event->setValue($existingValue->getConfigvalue());
				return;
			}
		} else {
			$existingValue = $this->preferenceService->getUserConfigValues(
				$event->getUser()->getUID(),
				$event->getApp(),
				[$event->getFieldId()],
			);
			if (!empty($existingValue)) {
				$event->setValue($existingValue[0]['configvalue']);
				return;
			}
		}

		foreach ($formSchema['fields'] as $field) {
			if (isset($field['default']) && $field['id'] === $event->getFieldId()) {
				if (is_array($field['default']) || is_numeric($field['default'])) {
					$default = json_encode($field['default']);
				} else {
					$default = $field['default'];
				}
				$event->setValue($default);
				return;
			}
		}
	}
}
