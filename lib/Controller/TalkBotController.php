<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\TalkBotsService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class TalkBotController extends OCSController {
	protected $request;
	private AppEcosystemV2Service $service;
	private TalkBotsService $talkBotsService;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
		TalkBotsService $talkBotsService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
		$this->talkBotsService = $talkBotsService;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $name bot display name
	 * @param string $route ExApp route to post messages
	 * @param string $description
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[AppEcosystemAuth]
	#[NoCSRFRequired]
	#[PublicPage]
	public function registerExAppTalkBot(string $name, string $route, string $description): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$botRegistered = $this->talkBotsService->registerExAppBot($exApp, $name, $route, $description);
		if ($botRegistered === null) {
			throw new OCSBadRequestException('Talk bots could not be registered');
		}
		return new DataResponse($botRegistered);
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $id
	 * @return Response
	 */
	#[AppEcosystemAuth]
	public function unregisterExAppTalkBot(string $id): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$botUnregistered = $this->talkBotsService->unregisterExAppBot($exApp, $id);
		return new DataResponse($botUnregistered);
	}
}
