<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class OCSApiController extends OCSController {
	protected $request;
	private AppAPIService $service;
	private LoggerInterface $logger;

	public function __construct(
		IRequest        $request,
		AppAPIService   $service,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->service = $service;
		$this->logger = $logger;
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $level
	 * @param string $message
	 *
	 * @throws OCSBadRequestException
	 * @return DataResponse
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function log(int $level, string $message): DataResponse {
		try {
			$appId = $this->request->getHeader('EX-APP-ID');
			$exApp = $this->service->getExApp($appId);
			if ($exApp === null) {
				$this->logger->error('ExApp ' . $appId . ' not found');
				throw new OCSBadRequestException('ExApp not found');
			}
			$exAppEnabled = $exApp->getEnabled();
			if ($exAppEnabled !== 1) {
				$this->logger->error('ExApp ' . $appId . ' is disabled');
				throw new OCSBadRequestException('ExApp is disabled');
			}
			$this->logger->log($level, $message, [
				'app' => $appId,
			]);
			return new DataResponse();
		} catch (\Psr\Log\InvalidArgumentException) {
			$this->logger->error('Invalid log level');
			throw new OCSBadRequestException('Invalid log level');
		}
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return DataResponse
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getNCUsersList(): DataResponse {
		return new DataResponse($this->service->getNCUsersList(), Http::STATUS_OK);
	}
}
