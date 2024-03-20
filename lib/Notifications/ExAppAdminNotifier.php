<?php

declare(strict_types=1);

namespace OCA\AppAPI\Notifications;

use InvalidArgumentException;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\ExAppService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class ExAppAdminNotifier implements INotifier {

	public function __construct(
		private readonly IFactory      $factory,
		private readonly IURLGenerator $url,
		private readonly ExAppService  $service,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->factory->get(Application::APP_ID)->t('AppAPI ExApp version update notifier');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getSubject() !== 'ex_app_version_update') {
			throw new InvalidArgumentException();
		}
		$exApp = $this->service->getExApp($notification->getApp());
		if ($exApp === null) {
			throw new InvalidArgumentException();
		}
		if ($exApp->getEnabled()) {
			throw new InvalidArgumentException('ExApp is probably already re-enabled');
		}

		$parameters = $notification->getSubjectParameters();

		$notification->setLink($this->url->getAbsoluteURL('/index.php/settings/admin/' . Application::APP_ID));
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
