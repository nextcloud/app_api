<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\SpeechToTextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;

class SpeechToTextController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly SpeechToTextService $speechToTextService,
		private readonly IConfig             $config,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerProvider(string $name, string $displayName, string $actionHandler): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		$appId = $this->request->getHeader('EX-APP-ID');
		$provider = $this->speechToTextService->registerSpeechToTextProvider($appId, $name, $displayName, $actionHandler);
		if ($provider === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterProvider(string $name): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		$appId = $this->request->getHeader('EX-APP-ID');
		$unregistered = $this->speechToTextService->unregisterSpeechToTextProvider($appId, $name);
		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}
}
