<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Notifications;

use OCA\AppEcosystemV2\Db\ExApp;
use OCP\Notification\IManager;
use OCP\Notification\INotification;

class ExNotificationsManager {
	private IManager $manager;
	public function __construct(IManager $manager) {
		$this->manager = $manager;
	}

	public function getNotifications(ExApp $exApp): array {
		return [];
	}

	public function getUserNotifications(ExApp $exApp, string $userId): array {
		// TODO
		return [];
	}

	/**
	 * Create a notification for ExApp and notify the user
	 *
	 * @param ExApp $exApp
	 * @param string|null $userId
	 * @param array $params
	 *
	 * @return INotification
	 */
	public function sendNotification(ExApp $exApp, ?string $userId = null, array $params = []): INotification {
		$notification = $this->manager->createNotification();
		$notification
			->setApp($exApp->getAppid())
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject($params['object'], $params['object_id'])
			->setSubject($params['subject'], $params['subject_params']);
		// TODO: Define dynamic way for other options (e.g. notification actions)
		$this->manager->notify($notification);
		return $notification;
	}

	public function removeNotifications(ExApp $exApp, ?string $userId = null): int {
		// TODO
		return -1;
	}
}
