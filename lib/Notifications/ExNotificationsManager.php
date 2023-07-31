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
			->setSubject($params['subject'], $params['subject_params']);
		if (isset($params['actions'])) {
			$notification = $this->buildNotificationActions($notification, $params['actions']);
		}
		$this->manager->notify($notification);
		return $notification;
	}

	public function buildNotificationActions(INotification $notification, array $actions): INotification {
		foreach ($actions as $actionParams) {
			$action = $notification->createAction();
			$action->setLabel($actionParams['label']);
			$action->setLink($actionParams['link'], $actionParams['method']);
			$action->setPrimary(filter_var($actionParams['primary'], FILTER_VALIDATE_BOOLEAN));
			$notification->addAction($action);
		}
		return $notification;
	}
}
