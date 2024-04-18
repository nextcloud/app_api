<?php

declare(strict_types=1);

namespace OCA\AppAPI;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use Psr\Http\Message\ResponseInterface;

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
	):  array|IResponse {
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
	):  array|IResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return ['error' => sprintf('ExApp `%s` not found', $appId)];
		}
		return $this->service->aeRequestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Request to ExApp with AppAPI auth headers using clean Guzzle client
	 */
	public function exAppRequestGuzzle(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	):  array|IResponse|ResponseInterface {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return ['error' => sprintf('ExApp `%s` not found', $appId)];
		}
		return $this->service->requestToExAppGuzzle($exApp, $route, $userId, $method, $params, $options, $request);
	}
}
