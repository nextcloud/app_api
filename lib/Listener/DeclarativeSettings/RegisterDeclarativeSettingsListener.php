<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeclarativeSettings\DeclarativeSettingsForm;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Settings\RegisterDeclarativeSettingsFormEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<RegisterDeclarativeSettingsFormEvent>
 */
class RegisterDeclarativeSettingsListener implements IEventListener {
	public function __construct(
		private readonly IConfig $config,
		private readonly DeclarativeSettingsForm $form,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterDeclarativeSettingsFormEvent) {
			return;
		}

		$this->logger->info('Registering declarative settings form');
		// TODO: Rewrite to go through registered ExApps forms and register them
		$event->registerSchema(Application::APP_ID, $this->form->getSchema());
	}
}
