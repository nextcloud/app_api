<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\TalkBotsService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class TalkBotController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly ExAppService    $service,
		private readonly TalkBotsService $talkBotsService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @param string $name bot display name
	 * @param string $route ExApp route to post messages
	 * @param string $description
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[AppAPIAuth]
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
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[NoCSRFRequired]
	#[PublicPage]
	public function unregisterExAppTalkBot(string $route): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$botUnregistered = $this->talkBotsService->unregisterExAppBot($exApp, $route);
		if ($botUnregistered === null) {
			throw new OCSNotFoundException('Talk bots could not be unregistered');
		}
		return new DataResponse($botUnregistered);
	}
}
