<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OC\AppFramework\Http;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class ExAppRoutesController extends OCSController {

	public function __construct(
		IRequest                             $request,
		private readonly ExAppService        $exAppService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerExAppRoutes(array $routes): DataResponse {
		if (!$this->validateRoutes($routes)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		$exApp = $this->exAppService->registerExAppRoutes($this->exAppService->getExApp($this->request->getHeader('EX-APP-ID')), $routes);
		if ($exApp === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterExAppRoutes(): DataResponse {
		$exApp = $this->exAppService->removeExAppRoutes($this->exAppService->getExApp($this->request->getHeader('EX-APP-ID')));
		if ($exApp === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppRoutes(): array {
		return $this->exAppService->getExApp($this->request->getHeader('EX-APP-ID'))->getRoutes();
	}

	private function validateRoutes(array $routes): bool {
		foreach ($routes as $route) {
			if (!isset($route['url']) || !isset($route['verb']) || !isset($route['access_level'])) {
				return false;
			}
		}
		return true;
	}
}
