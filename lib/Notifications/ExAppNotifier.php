<?php

declare(strict_types=1);

namespace OCA\AppAPI\Notifications;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\AppAPIService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class ExAppNotifier implements INotifier {
	private IFactory $factory;
	private IURLGenerator $url;
	private AppAPIService $service;

	public function __construct(
		IFactory      $factory,
		IURLGenerator $urlGenerator,
		AppAPIService $service,
	) {
		$this->factory = $factory;
		$this->url = $urlGenerator;
		$this->service = $service;
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->factory->get(Application::APP_ID)->t('AppAPI ExApp notifier');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$exApp = $this->service->getExApp($notification->getApp());
		if ($exApp === null) {
			throw new \InvalidArgumentException();
		}
		if ($notification->getSubject() === 'ex_app_version_update' && $exApp->getEnabled()) {
			throw new \InvalidArgumentException('ExApp is probably already re-enabled');
		} elseif (!$exApp->getEnabled()) { // Only enabled ExApps can render notifications
			throw new \InvalidArgumentException('ExApp is disabled');
		}

		$parameters = $notification->getSubjectParameters();
		if (isset($parameters['link']) && $parameters['link'] !== '') {
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
