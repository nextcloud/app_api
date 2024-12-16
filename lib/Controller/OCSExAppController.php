<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\AppAPIService;

use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;

class OCSExAppController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                       $request,
		private readonly AppAPIService $service,
		private readonly ExAppService  $exAppService,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	public function getExAppsList(string $list = 'enabled'): DataResponse {
		if (!in_array($list, ['all', 'enabled'])) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->exAppService->getExAppsList($list), Http::STATUS_OK);
	}

	#[NoCSRFRequired]
	public function getExApp(string $appId): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->exAppService->formatExAppInfo($exApp), Http::STATUS_OK);
	}

	#[NoCSRFRequired]
	public function getNextcloudUrl(): DataResponse {
		return new DataResponse([
			'base_url' => $this->urlGenerator->getBaseUrl(),
		], Http::STATUS_OK);
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[NoCSRFRequired]
	public function setExAppEnabled(string $appId, int $enabled): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
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

	#[NoCSRFRequired]
	public function requestToExApp(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
	): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return new DataResponse(['error' => sprintf('ExApp `%s` not found', $appId)]);
		}
		$response = $this->service->requestToExApp($exApp, $route, $userId, $method, $params, $options, $this->request);
		if (is_array($response) && isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([
			'status_code' => $response->getStatusCode(),
			'headers' => $response->getHeaders(),
			'body' => $response->getBody(),
		]);
	}

	/**
	 * TODO: remove later
	 *
	 * @deprecated since AppAPI 3.0.0
	 */
	#[NoCSRFRequired]
	public function aeRequestToExApp(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
	): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return new DataResponse(['error' => sprintf('ExApp `%s` not found', $appId)]);
		}
		$response = $this->service->requestToExApp($exApp, $route, $userId, $method, $params, $options, $this->request);
		if (is_array($response) && isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([
			'status_code' => $response->getStatusCode(),
			'headers' => $response->getHeaders(),
			'body' => $response->getBody(),
		]);
	}
}
