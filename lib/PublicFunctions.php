<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
	 *
	 * @deprecated since AppAPI 3.0.0, use `exAppRequest` instead
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
		return $this->service->requestToExApp($exApp, $route, $userId, $method, $params, $options, $request);
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
	): IPromise {
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
	 *
	 * @deprecated since AppAPI 3.0.0, use `asyncExAppRequest` instead
	 */
	public function asyncExAppRequestWithUserInit(
		string $appId,
		string $route,
		?string $userId = null,
		string $method = 'POST',
		array $params = [],
		array $options = [],
		?IRequest $request = null,
	): IPromise {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			throw new \Exception(sprintf('ExApp `%s` not found', $appId));
		}
		return $this->service->requestToExAppAsync($exApp, $route, $userId, $method, $params, $options, $request);
	}

	/**
	 * Get basic ExApp info by appid
	 *
	 * @param string $appId
	 *
	 * @return array|null ExApp info (appid, version, name, enabled) or null if no ExApp found
	 */
	public function getExApp(string $appId): ?array {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp !== null) {
			$info = $exApp->jsonSerialize();
			return [
				'appid' => $info['appid'],
				'version' => $info['version'],
				'name' => $info['name'],
				'enabled' => $info['enabled'],
			];
		}
		return null;
	}
}
