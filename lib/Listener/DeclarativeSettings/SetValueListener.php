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
use OCP\Security\ICrypto;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class SetValueListener implements IEventListener {
	public function __construct(
		private readonly SettingsService $service,
		private readonly ExAppPreferenceService $preferenceService,
		private readonly ExAppConfigService $configService,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof DeclarativeSettingsSetValueEvent) {
			return;
		}

		$settingsForm = $this->service->getForm($event->getApp(), $event->getFormId());
		if ($settingsForm === null) {
			return;
		}
		$formSchema = $settingsForm->getScheme();
		$field = $settingsForm->getSchemaField($event->getFieldId());
		$isSensitive = isset($field['sensitive']) && $field['sensitive'] === true;
		$value = $event->getValue();
		if ($isSensitive) {
			try {
				$value = $this->crypto->encrypt($value);
			} catch (\Exception $e) {
				$this->logger->warning(
					sprintf('Failed to encrypt sensitive value for app %s, field %s', $event->getApp(), $event->getFieldId()),
					['exception' => $e, 'app' => $event->getApp()]
				);
				return;
			}
		}
		if ($formSchema['section_type'] === DeclarativeSettingsTypes::SECTION_TYPE_ADMIN) {
			$this->configService->setAppConfigValue($event->getApp(), $event->getFieldId(), $value);
		} else {
			$this->preferenceService->setUserConfigValue(
				$event->getUser()->getUID(), $event->getApp(), $event->getFieldId(), $value
			);
		}
	}
}
