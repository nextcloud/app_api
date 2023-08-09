<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class ExAppController extends OCSController {
	private AppEcosystemV2Service $service;

	public function __construct(
		IRequest $request,
		AppEcosystemV2Service $service,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->service = $service;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $list
	 *
	 * @throws OCSBadRequestException
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	public function getExApps(string $list = 'enabled'): DataResponse {
		if (!in_array($list, ['all', 'enabled'])) {
			throw new OCSBadRequestException();
		}
		return new DataResponse($this->service->getExAppsList($list), Http::STATUS_OK);
	}
}
