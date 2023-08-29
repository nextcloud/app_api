<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\SpeechToTextService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
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

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $name
	 * @param string $displayName
	 * @param string $actionHandlerRoute
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppEcosystemAuth]
	public function registerProvider(string $name, string $displayName, string $actionHandlerRoute): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);

		$provider = $this->speechToTextService->registerSpeechToTextProvider($exApp, $name, $displayName, $actionHandlerRoute);

		if ($provider === null) {
			throw new OCSBadRequestException('Failed to register STT provider');
		}

		return new DataResponse();
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $name
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppEcosystemAuth]
	public function unregisterProvider(string $name): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$unregistered = $this->speechToTextService->unregisterSpeechToTextProvider($exApp, $name);

		if ($unregistered === null) {
			throw new OCSBadRequestException('Failed to unregister STT provider');
		}

		return new DataResponse();
	}
}
