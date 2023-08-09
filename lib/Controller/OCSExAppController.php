<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class OCSExAppController extends OCSController {
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
	public function getExAppsList(string $list = 'enabled'): DataResponse {
		if (!in_array($list, ['all', 'enabled'])) {
			throw new OCSBadRequestException();
		}
		return new DataResponse($this->service->getExAppsList($list), Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param int $enabled
	 *
	 * @throws OCSNotFoundException
	 * @throws OCSBadRequestException
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	public function setExAppEnabled(string $appId, int $enabled): DataResponse {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			throw new OCSNotFoundException('ExApp not found');
		}

		if (filter_var($enabled, FILTER_VALIDATE_BOOL)) {
			if ($exApp->getEnabled()) {
				throw new OCSBadRequestException('ExApp already enabled');
			}
			if (!$this->service->enableExApp($exApp)) {
				throw new OCSBadRequestException('Failed to enable ExApp');
			}
		} else {
			if (!$exApp->getEnabled()) {
				throw new OCSBadRequestException('ExApp already disabled');
			}
			if (!$this->service->disableExApp($exApp)) {
				throw new OCSBadRequestException('Failed to disable ExApp');
			}
		}

		return new DataResponse();
	}
}
