<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\ResponseDefinitions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * @psalm-import-type AppAPIExApp from ResponseDefinitions
 * @psalm-import-type AppAPIExAppRequestResult from ResponseDefinitions
 */
class OCSExAppController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly AppAPIService $service,
		private readonly ExAppService $exAppService,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Get a list of registered ExApps
	 *
	 * @param 'all'|'enabled' $list Which ExApps to return
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AppAPIExApp>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, list<empty>, array{}>
	 *
	 * 200: ExApps list returned
	 * 400: Invalid list type
	 */
	#[NoCSRFRequired]
	public function getExAppsList(string $list = 'enabled'): DataResponse {
		if (!in_array($list, ['all', 'enabled'])) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->exAppService->getExAppsList($list), Http::STATUS_OK);
	}

	/**
	 * Get information about a registered ExApp
	 *
	 * @param string $appId ID of the ExApp
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIExApp, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: ExApp info returned
	 * 404: ExApp not found
	 */
	#[NoCSRFRequired]
	public function getExApp(string $appId): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if (!$exApp) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->exAppService->formatExAppInfo($exApp), Http::STATUS_OK);
	}

	/**
	 * Get the base URL of this Nextcloud instance
	 *
	 * @return DataResponse<Http::STATUS_OK, array{base_url: string}, array{}>
	 *
	 * 200: Base URL returned
	 */
	#[NoCSRFRequired]
	public function getNextcloudUrl(): DataResponse {
		return new DataResponse([
			'base_url' => $this->urlGenerator->getBaseUrl(),
		], Http::STATUS_OK);
	}

	/**
	 * Enable or disable a registered ExApp
	 *
	 * @param string $appId ID of the ExApp
	 * @param int $enabled New state: 1 to enable, 0 to disable
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 * @throws OCSBadRequestException ExApp is already in the requested state or the state change failed
	 *
	 * 200: ExApp state changed
	 * 404: ExApp not found
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

	/**
	 * Proxy a request from one ExApp to another registered ExApp
	 *
	 * @param string $appId ID of the target ExApp
	 * @param string $route Route inside the target ExApp to call
	 * @param ?string $userId User to perform the request on behalf of, or null
	 * @param string $method HTTP method to use
	 * @param array<string, mixed> $params Request parameters
	 * @param array<string, mixed> $options Additional request options
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIExAppRequestResult|array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 * @psalm-suppress InvalidReturnType, InvalidReturnStatement The proxied ExApp response body is dynamically typed
	 *
	 * 200: ExApp response returned
	 * 400: Request to the ExApp failed
	 */
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
	 * Proxy a request from one ExApp to another registered ExApp (deprecated alias)
	 *
	 * @param string $appId ID of the target ExApp
	 * @param string $route Route inside the target ExApp to call
	 * @param ?string $userId User to perform the request on behalf of, or null
	 * @param string $method HTTP method to use
	 * @param array<string, mixed> $params Request parameters
	 * @param array<string, mixed> $options Additional request options
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIExAppRequestResult|array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 * @psalm-suppress InvalidReturnType, InvalidReturnStatement The proxied ExApp response body is dynamically typed
	 *
	 * 200: ExApp response returned
	 * 400: Request to the ExApp failed
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
