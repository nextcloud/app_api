<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Notifications;

use OCP\Notification\IManager;
use OCP\Notification\INotification;

class ExNotificationsManager {
	private IManager $manager;

	public function __construct(IManager $manager) {
		$this->manager = $manager;
	}

	/**
	 * Create a notification for ExApp and notify the user
	 *
	 * @param string $appId
	 * @param string|null $userId
	 * @param array $params
	 *
	 * @return INotification
	 */
	public function sendNotification(string $appId, ?string $userId = null, array $params = []): INotification {
		$notification = $this->manager->createNotification();
		$notification
			->setApp($appId)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject($params['object'], $params['object_id'])
			->setSubject($params['subject_type'], $params['subject_params']);
		$this->manager->notify($notification);
		return $notification;
	}
}
