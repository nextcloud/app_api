<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Notifications\ExNotificationsManager;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class NotificationsController extends OCSController {
	private ExNotificationsManager $exNotificationsManager;
	protected $request;

	public function __construct(
		IRequest $request,
		ExNotificationsManager $exNotificationsManager,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->exNotificationsManager = $exNotificationsManager;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param array $params
	 *
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function sendNotification(array $params): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$userId = $this->request->getHeader('NC-USER-ID');
		$notification = $this->exNotificationsManager->sendNotification($appId, $userId, $params);
		return new DataResponse($notification, Http::STATUS_OK);
	}
}
