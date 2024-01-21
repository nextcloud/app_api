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
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

class OCSApiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly AppAPIService   $service,
		private readonly LoggerInterface $logger,
		private readonly IUserManager    $userManager,
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
		} catch (InvalidArgumentException) {
			$this->logger->error('Invalid log level');
			throw new OCSBadRequestException('Invalid log level');
		}
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getNCUsersList(): DataResponse {
		return new DataResponse(
			array_map(function (IUser $user) {
				return $user->getUID();
			}, $this->userManager->searchDisplayName('')),
			Http::STATUS_OK
		);
	}

	/**
	 * Get ExApp status, that required during initialization step with progress information
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setAppProgress(string $appId, int $progress, string $error = ''): DataResponse {
		$this->service->setAppInitProgress($appId, $progress, $error);
		return new DataResponse();
	}
}
