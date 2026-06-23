<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\ResponseDefinitions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ProvidersAI\TaskProcessingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type AppAPITaskProcessingProvider from ResponseDefinitions
 */
class TaskProcessingController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly TaskProcessingService $taskProcessingService,
		private readonly AppAPIService $appAPIService,
		private readonly ExAppService $exAppService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
		$this->taskProcessingService->setAppAPIService($this->appAPIService);
		$this->taskProcessingService->setExAppService($this->exAppService);
	}

	/**
	 * Register a Task Processing provider for the calling ExApp
	 *
	 * @param array<string, mixed> $provider Task Processing provider definition
	 * @param array<string, mixed>|null $customTaskType Custom task type definition, or null
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, list<empty>, array{}>
	 *
	 * 200: Provider registered
	 * 400: Provider could not be registered
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerProvider(
		array $provider,
		?array $customTaskType,
	): DataResponse {
		$providerObj = $this->taskProcessingService->registerTaskProcessingProvider(
			$this->request->getHeader('ex-app-id'),
			$provider,
			$customTaskType,
		);

		if ($providerObj === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	/**
	 * Unregister a Task Processing provider of the calling ExApp
	 *
	 * @param string $name Name of the provider to remove
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Provider unregistered
	 * 404: Provider not found
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterProvider(string $name): Response {
		$unregistered = $this->taskProcessingService->unregisterTaskProcessingProvider(
			$this->request->getHeader('ex-app-id'), $name
		);

		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse();
	}

	/**
	 * Get a Task Processing provider of the calling ExApp
	 *
	 * @param string $name Name of the provider
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPITaskProcessingProvider, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Provider returned
	 * 404: Provider not found
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function getProvider(string $name): DataResponse {
		$result = $this->taskProcessingService->getExAppTaskProcessingProvider(
			$this->request->getHeader('ex-app-id'), $name
		);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}
}
