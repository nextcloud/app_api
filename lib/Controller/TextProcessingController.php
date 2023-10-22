<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppEcosystemAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\TextProcessingService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class TextProcessingController extends OCSController {
	protected $request;
	private AppEcosystemV2Service $service;
	private TextProcessingService $textProcessingService;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
		TextProcessingService $textProcessingService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
		$this->textProcessingService = $textProcessingService;
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $name
	 * @param string $displayName
	 * @param string $description
	 * @param string $actionHandlerRoute
	 * @param string $actionType
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppEcosystemAuth]
	public function registerProvider(string $name, string $displayName, string $description, string $actionHandlerRoute, string $actionType): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);

		$provider = $this->textProcessingService->registerTextProcesingProvider($exApp, $name, $displayName, $description, $actionHandlerRoute);

		if ($provider === null) {
			throw new OCSBadRequestException('Failed to register text processing provider');
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
		$unregistered = $this->textProcessingService->unregisterTextProcessingProvider($exApp, $name);

		if ($unregistered === null) {
			throw new OCSBadRequestException('Failed to unregister text processing provider');
		}

		return new DataResponse();
	}
}
