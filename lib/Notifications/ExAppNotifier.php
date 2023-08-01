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

		$subjectParameters = $notification->getSubjectParameters();
		if (isset($subjectParameters['link'])) {
			$notification->setLink($subjectParameters['link']);
		}
		$notification->setIcon($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));

		if (isset($subjectParameters['rich_subject']) && isset($subjectParameters['rich_subject_params'])) {
			$notification->setRichSubject($subjectParameters['rich_subject'], $subjectParameters['rich_subject_params']);
		}
		if (isset($subjectParameters['rich_message']) && isset($subjectParameters['rich_message_params'])) {
			$notification->setRichMessage($subjectParameters['rich_message'], $subjectParameters['rich_message_params']);
		}

		return $notification;
	}
}
