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
		IRequest                         $request,
		private readonly SettingsService $settingsService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerForm(array $formScheme): DataResponse {
		$settingsForm = $this->settingsService->registerForm(
			$this->request->getHeader('EX-APP-ID'), $formScheme);
		if ($settingsForm === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterForm(string $formId): DataResponse {
		$unregistered = $this->settingsService->unregisterForm(
			$this->request->getHeader('EX-APP-ID'), $formId);
		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getForm(string $formId): DataResponse {
		$result = $this->settingsService->getForm(
			$this->request->getHeader('EX-APP-ID'), $formId);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result->getScheme(), Http::STATUS_OK);
	}
}
