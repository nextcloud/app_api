<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\SpeechToTextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class SpeechToTextController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly AppAPIService $service,
		private readonly SpeechToTextService $speechToTextService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerProvider(string $name, string $displayName, string $actionHandlerRoute): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);

		$provider = $this->speechToTextService->registerSpeechToTextProvider($exApp, $name, $displayName, $actionHandlerRoute);
		if ($provider === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterProvider(string $name): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$unregistered = $this->speechToTextService->unregisterSpeechToTextProvider($exApp, $name);
		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}
}
