<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\ExAppSetupCheckService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class SetupCheckController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly ExAppSetupCheckService $setupCheckService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Opt the calling ExApp in to setup checks
	 *
	 * The ExApp is identified from the authenticated `ex-app-id` header, so it can only ever opt
	 * itself in. Its live results are then fetched from the ExApp's `/setup_checks` endpoint by a
	 * background job and surfaced in the admin "Security & setup warnings" panel.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 *
	 * 200: ExApp opted in to setup checks
	 */
	#[AppAPIAuth]
	#[NoCSRFRequired]
	#[PublicPage]
	public function registerChecks(): DataResponse {
		$this->setupCheckService->optIn($this->request->getHeader('ex-app-id'));
		return new DataResponse();
	}

	/**
	 * Opt the calling ExApp out of setup checks
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 *
	 * 200: ExApp opted out of setup checks
	 */
	#[AppAPIAuth]
	#[NoCSRFRequired]
	#[PublicPage]
	public function unregisterChecks(): DataResponse {
		$this->setupCheckService->optOut($this->request->getHeader('ex-app-id'));
		return new DataResponse();
	}
}
