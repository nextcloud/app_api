<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Notifications\ExNotificationsManager;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class NotificationsController extends OCSController {
	private AppEcosystemV2Service $service;
	private ExNotificationsManager $exNotificationsManager;
	protected $request;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
		ExNotificationsManager $exNotificationsManager,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
		$this->exNotificationsManager = $exNotificationsManager;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param array $params [object, object_id, subject, subject_params]
	 *
	 * @throws OCSNotFoundException
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function sendNotification(array $params = []): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$userId = $this->request->getHeader('NC-USER-ID');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			throw new OCSNotFoundException('ExApp not found');
		}
		$notification = $this->exNotificationsManager->sendNotification($exApp, $userId, $params);
		return new DataResponse($notification, Http::STATUS_OK);
	}
}
