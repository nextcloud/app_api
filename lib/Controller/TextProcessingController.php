<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class TextProcessingController extends OCSController {
	protected $request;
	private AppEcosystemV2Service $service;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
	}

	public function registerProvider(): Response {
		return new DataResponse();
	}
}
