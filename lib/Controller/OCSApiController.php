<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

class OCSApiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly LoggerInterface $logger,
		private readonly AppAPIService   $service,
		private IConfig $config,
		private readonly ExAppService	 $exAppService,
		private readonly IURLGenerator   $urlGenerator,
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

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setAppInitProgressDeprecated(string $appId, int $progress, string $error = ''): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$this->service->setAppInitProgress($exApp, $progress, $error);
		return new DataResponse();
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setAppInitProgress(int $progress, string $error = ''): DataResponse {
		$exApp = $this->exAppService->getExApp($this->request->getHeader('EX-APP-ID'));
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$this->service->setAppInitProgress($exApp, $progress, $error);
		return new DataResponse();
	}

	/**
	 * Retrieves the enabled status of an ExApp (0 for disabled, 1 for enabled).
	 * Note: This endpoint is accessible even if the ExApp itself is disabled.
	 *
	 * @return DataResponse The enabled status of the ExApp.
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getEnabledState(): DataResponse {
		$exApp = $this->exAppService->getExApp($this->request->getHeader('EX-APP-ID'));
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($exApp->getEnabled());
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getNextcloudAbsoluteUrl(string $url): DataResponse {
		return new DataResponse([
			'absolute_url' => rtrim($this->config->getSystemValueString('overwrite.cli.url'), '/') . '/' . ltrim($url, '/'),
		], Http::STATUS_OK);
	}
}
