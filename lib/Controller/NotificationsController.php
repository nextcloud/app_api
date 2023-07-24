<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Notifications\ExNotificationsManager;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;
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
	private ExFilesActionsMenuService $exFilesActionsMenuService;
	private ExNotificationsManager $exNotificationsManager;
	protected $request;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
		ExFilesActionsMenuService $exFilesActionsMenuService,
		ExNotificationsManager $exNotificationsManager,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
		$this->exNotificationsManager = $exNotificationsManager;
	}

	/**
	 * @AppEcosystemAuth
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @throws OCSNotFoundException
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getNotifications(): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			throw new OCSNotFoundException('ExApp not found');
		}
		return new DataResponse($this->exNotificationsManager->getNotifications($exApp), Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $fileActionMenuName
	 * @param array $params [object, message]
	 *
	 * @throws OCSNotFoundException
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function sendNotification(string $fileActionMenuName, array $params = []): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$userId = $this->request->getHeader('NC-USER-ID');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			throw new OCSNotFoundException('ExApp not found');
		}
		$fileActionMenu = $this->exFilesActionsMenuService->getExAppFileAction($exApp->getAppid(), $fileActionMenuName);
		if ($fileActionMenu === null) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		$notification = $this->exNotificationsManager->sendNotification($exApp, $userId, $params);
		return new DataResponse($notification, Http::STATUS_OK);
	}
}
