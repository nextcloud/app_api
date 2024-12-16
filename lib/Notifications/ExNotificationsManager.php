<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Notifications;

use DateTime;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;

class ExNotificationsManager {

	public function __construct(
		private readonly IManager      $notificationManager,
		private readonly IGroupManager $groupManager
	) {
	}

	/**
	 * Create a notification for ExApp and notify the user
	 */
	public function sendNotification(string $appId, ?string $userId = null, array $params = []): INotification {
		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp($appId)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject($params['object'], $params['object_id'])
			->setSubject($params['subject_type'], $params['subject_params']);
		$this->notificationManager->notify($notification);
		return $notification;
	}

	public function sendAdminsNotification(string $appId, array $params = []): array {
		$admins = $this->groupManager->get("admin")->getUsers();
		$notifications = [];
		foreach ($admins as $adminUser) {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp($appId)
				->setUser($adminUser->getUID())
				->setDateTime(new DateTime())
				->setObject($params['object'], $params['object_id'])
				->setSubject($params['subject_type'], $params['subject_params']);
			$this->notificationManager->notify($notification);
			$notifications[] = $notification;
		}
		return $notifications;
	}
}
