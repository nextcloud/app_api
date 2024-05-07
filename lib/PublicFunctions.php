<?php

declare(strict_types=1);

namespace OCA\AppAPI;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use OCP\IRequest;

class PublicFunctions {

	public function __construct(
		private readonly ExAppService  $exAppService,
		private readonly AppAPIService $service,
	) {
	}

	/**
	 * Request to ExApp with AppAPI auth headers
	 */
	public function exAppRequest(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return ['error' => sprintf('ExApp `%s` not found', $appId)];
		}
		return $this->service->requestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Request to ExApp with AppAPI auth headers and ExApp user initialization
	 */
	public function exAppRequestWithUserInit(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return ['error' => sprintf('ExApp `%s` not found', $appId)];
		}
		return $this->service->aeRequestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Async request to ExApp with AppAPI auth headers
	 *
	 * @throws \Exception if ExApp not found
	 */
	public function asyncExAppRequest(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IPromise {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			throw new \Exception(sprintf('ExApp `%s` not found', $appId));
		}
		return $this->service->requestToExAppAsync($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Async request to ExApp with AppAPI auth headers and ExApp user initialization
	 *
	 * @throws \Exception if ExApp not found or failed to setup ExApp user
	 */
	public function asyncExAppRequestWithUserInit(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): array|IPromise {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			throw new \Exception(sprintf('ExApp `%s` not found', $appId));
		}
		return $this->service->aeRequestToExAppAsync($exApp, $route, $userId, $method, $params, $options, $request);
	}
}
