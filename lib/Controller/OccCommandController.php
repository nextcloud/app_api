<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\ExAppOccService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class OccCommandController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly ExAppOccService $service
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerCommand(
		string $name,
		string $description,
		string $execute_handler,
		int $hidden = 0,
		array $arguments = [],
		array $options = [],
		array $usages = [],
	): DataResponse {
		$command = $this->service->registerCommand(
			$this->request->getHeader('EX-APP-ID'), $name,
			$description, $hidden, $arguments, $options, $usages, $execute_handler
		);
		if ($command === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterCommand(string $name): DataResponse {
		$unregistered = $this->service->unregisterCommand($this->request->getHeader('EX-APP-ID'), $name);
		if (!$unregistered) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getCommand(string $name): DataResponse {
		$result = $this->service->getOccCommand($this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
