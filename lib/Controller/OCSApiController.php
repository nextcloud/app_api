<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Attribute\MaintenanceModeAvailable;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

class OCSApiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly AppAPIService $service,
		private IConfig $config,
		private readonly ExAppService $exAppService,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Log a message to the Nextcloud log on behalf of an ExApp
	 *
	 * @param int $level Log level (0 - debug, 1 - info, 2 - warning, 3 - error, 4 - fatal)
	 * @param string $message Message to log
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Invalid log level
	 *
	 * 200: Message logged successfully
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[MaintenanceModeAvailable]
	public function log(int $level, string $message): DataResponse {
		try {
			$this->logger->log($level, $message, [
				'app' => $this->request->getHeader('ex-app-id'),
			]);
			return new DataResponse();
		} catch (InvalidArgumentException) {
			$this->logger->error('Invalid log level');
			throw new OCSBadRequestException('Invalid log level');
		}
	}

	/**
	 * Get a list of all Nextcloud user IDs
	 *
	 * @return DataResponse<Http::STATUS_OK, list<string>, array{}>
	 *
	 * 200: Users list returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getNCUsersList(): DataResponse {
		return new DataResponse($this->exAppService->getNCUsersList(), Http::STATUS_OK);
	}

	/**
	 * Update the initialization progress of an ExApp by its appid
	 *
	 * @param string $appId ID of the ExApp
	 * @param int $progress Initialization progress in percent (0-100)
	 * @param string $error Error message in case the initialization failed
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Initialization progress updated
	 * 404: ExApp not found
	 *
	 * @deprecated use setAppInitProgress (PUT /ex-app/status) instead
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	#[MaintenanceModeAvailable]
	public function setAppInitProgressDeprecated(string $appId, int $progress, string $error = ''): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$this->service->setAppInitProgress($exApp, $progress, $error);
		return new DataResponse();
	}

	/**
	 * Update the initialization progress of the calling ExApp
	 *
	 * @param int $progress Initialization progress in percent (0-100)
	 * @param string $error Error message in case the initialization failed
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Initialization progress updated
	 * 404: ExApp not found
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	#[MaintenanceModeAvailable]
	public function setAppInitProgress(int $progress, string $error = ''): DataResponse {
		$exApp = $this->exAppService->getExApp($this->request->getHeader('ex-app-id'));
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$this->service->setAppInitProgress($exApp, $progress, $error);
		return new DataResponse();
	}

	/**
	 * Get the enabled state of the calling ExApp (0 for disabled, 1 for enabled)
	 *
	 * Note: This endpoint is accessible even if the ExApp itself is disabled.
	 *
	 * @return DataResponse<Http::STATUS_OK, int, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Enabled state returned
	 * 404: ExApp not found
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	#[MaintenanceModeAvailable]
	public function getEnabledState(): DataResponse {
		$exApp = $this->exAppService->getExApp($this->request->getHeader('ex-app-id'));
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($exApp->getEnabled());
	}

	/**
	 * Build an absolute URL from a Nextcloud-relative URL
	 *
	 * @param string $url Relative URL to convert into an absolute one
	 *
	 * @return DataResponse<Http::STATUS_OK, array{absolute_url: string}, array{}>
	 *
	 * 200: Absolute URL returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	#[MaintenanceModeAvailable]
	public function getNextcloudAbsoluteUrl(string $url): DataResponse {
		return new DataResponse([
			'absolute_url' => rtrim($this->config->getSystemValueString('overwrite.cli.url'), '/') . '/' . ltrim($url, '/'),
		], Http::STATUS_OK);
	}
}
