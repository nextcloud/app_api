<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;

use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

class OCSApiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly LoggerInterface $logger,
		private readonly AppAPIService   $service,
		private readonly ExAppService	 $exAppService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function log(int $level, string $message): DataResponse {
		try {
			$this->logger->log($level, $message, [
				'app' => $this->request->getHeader('EX-APP-ID'),
			]);
			return new DataResponse();
		} catch (InvalidArgumentException) {
			$this->logger->error('Invalid log level');
			throw new OCSBadRequestException('Invalid log level');
		}
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getNCUsersList(): DataResponse {
		return new DataResponse($this->exAppService->getNCUsersList(), Http::STATUS_OK);
	}

	/**
	 * Get ExApp status, that required during initialization step with progress information
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setAppInitProgress(string $appId, int $progress, string $error = ''): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$this->service->setAppInitProgress($exApp, $progress, $error);
		return new DataResponse();
	}
}
