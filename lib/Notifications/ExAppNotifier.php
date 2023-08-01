<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Notifications;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class ExAppNotifier implements INotifier {
	private IFactory $factory;
	private IURLGenerator $url;
	private AppEcosystemV2Service $service;

	public function __construct(
		IFactory $factory,
		IURLGenerator $urlGenerator,
		AppEcosystemV2Service $service,
	) {
		$this->factory = $factory;
		$this->url = $urlGenerator;
		$this->service = $service;
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->factory->get(Application::APP_ID)->t('AppEcosystemV2 ExApp notifier');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$exApp = $this->service->getExApp($notification->getApp());
		if ($exApp === null) {
			throw new \InvalidArgumentException();
		}
		// Only enabled ExApps can render notifications
		if (!$exApp->getEnabled()) {
			throw new \InvalidArgumentException('ExApp is disabled');
		}

		$parameters = $notification->getSubjectParameters();
		if (isset($parameters['link'])) {
			$notification->setLink($parameters['link']);
		}
		$notification->setIcon($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));

		if (isset($parameters['rich_subject']) && isset($parameters['rich_subject_params'])) {
			$notification->setRichSubject($parameters['rich_subject'], $parameters['rich_subject_params']);
		}
		if (isset($parameters['rich_message']) && isset($parameters['rich_message_params'])) {
			$notification->setRichMessage($parameters['rich_message'], $parameters['rich_message_params']);
		}

		return $notification;
	}
}
