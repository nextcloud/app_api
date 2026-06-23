<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class OCSSettingsController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly SettingsService $settingsService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Register a declarative settings form for the calling ExApp
	 *
	 * @param array<string, mixed> $formScheme Declarative settings form scheme
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, list<empty>, array{}>
	 *
	 * 200: Settings form registered
	 * 400: Settings form could not be registered
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerForm(array $formScheme): DataResponse {
		$settingsForm = $this->settingsService->registerForm(
			$this->request->getHeader('ex-app-id'), $formScheme);
		if ($settingsForm === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	/**
	 * Unregister a declarative settings form of the calling ExApp
	 *
	 * @param string $formId ID of the settings form to remove
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Settings form unregistered
	 * 404: Settings form not found
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterForm(string $formId): DataResponse {
		$unregistered = $this->settingsService->unregisterForm(
			$this->request->getHeader('ex-app-id'), $formId);
		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}

	/**
	 * Get a declarative settings form of the calling ExApp
	 *
	 * @param string $formId ID of the settings form
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Settings form returned
	 * 404: Settings form not found
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getForm(string $formId): DataResponse {
		$result = $this->settingsService->getForm(
			$this->request->getHeader('ex-app-id'), $formId);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result->getScheme(), Http::STATUS_OK);
	}
}
