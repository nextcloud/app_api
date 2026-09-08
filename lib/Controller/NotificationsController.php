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
use OCA\AppAPI\ResponseDefinitions;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type AppAPINotification from ResponseDefinitions
 */
class NotificationsController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private ExNotificationsManager $exNotificationsManager,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Send a notification to a user on behalf of the calling ExApp
	 *
	 * @param array<string, mixed> $params Notification parameters
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPINotification, array{}>
	 * @throws OCSNotFoundException The notifications app is not enabled, so nothing could deliver the notification
	 *
	 * 200: Notification sent
	 * 404: The notifications app is not enabled on this instance
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function sendNotification(array $params): Response {
		$appId = $this->request->getHeader('ex-app-id');
		// The core notification manager delivers to registered notifier apps only; with the notifications app
		// absent it delivers to nobody and reports nothing, so the ExApp would see a 200 for a lost notification.
		if (!$this->appManager->isEnabledForAnyone('notifications')) {
			$this->logger->warning('ExApp "{appId}" sent a notification, but the notifications app is not enabled', ['appId' => $appId]);
			throw new OCSNotFoundException('The notifications app is not enabled, the notification cannot be delivered');
		}
		$userId = explode(':', base64_decode($this->request->getHeader('authorization-app-api')), 2)[0];
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
