<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\SpeechToTextService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class SpeechToTextController extends OCSController {
	protected $request;
	private AppEcosystemV2Service $service;
	private SpeechToTextService $speechToTextService;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
		SpeechToTextService $speechToTextService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
		$this->speechToTextService = $speechToTextService;
	}

	public function registerProvider(): Response {
		return new DataResponse();
	}
}
