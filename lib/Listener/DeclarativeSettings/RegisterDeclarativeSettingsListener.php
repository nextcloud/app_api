<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener\DeclarativeSettings;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeclarativeSettings\DeclarativeSettingsForm;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Settings\RegisterDeclarativeSettingsFormEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<RegisterDeclarativeSettingsFormEvent>
 */
class RegisterDeclarativeSettingsListener implements IEventListener {
	public function __construct(
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
		$secondSchema = $this->form->getSchema();
		$secondSchema['id'] = Application::APP_ID . '_dup';
		$secondSchema['fields'] = array_map(function (array $field): array {
			$field['id'] = $field['id'] . '_dup';
			return $field;
		}, $secondSchema['fields']);
		$event->registerSchema(Application::APP_ID, $secondSchema);
	}
}
