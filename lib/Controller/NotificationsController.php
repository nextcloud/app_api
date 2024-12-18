<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Notifications\ExNotificationsManager;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\Notification\INotification;

class NotificationsController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private ExNotificationsManager $exNotificationsManager,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function sendNotification(array $params): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$userId = explode(':', base64_decode($this->request->getHeader('AUTHORIZATION-APP-API')), 2)[0];
		$notification = $this->exNotificationsManager->sendNotification($appId, $userId, $params);
		return new DataResponse($this->notificationToArray($notification), Http::STATUS_OK);
	}

	private function notificationToArray(INotification $notification): array {
		return [
			'app' => $notification->getApp(),
			'user' => $notification->getUser(),
			'datetime' => $notification->getDateTime()->format('c'),
			'object_type' => $notification->getObjectType(),
			'object_id' => $notification->getObjectId(),
			'subject' => $notification->getParsedSubject(),
			'message' => $notification->getParsedMessage(),
			'link' => $notification->getLink(),
			'icon' => $notification->getIcon(),
		];
	}
}
