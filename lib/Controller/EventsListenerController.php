<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\ExAppEventsListenerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class EventsListenerController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly ExAppEventsListenerService $service,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerListener(string $eventType, string $actionHandler, array $eventSubtypes = []): DataResponse {
		$listener = $this->service->registerEventsListener(
			$this->request->getHeader('EX-APP-ID'), $eventType, $actionHandler, $eventSubtypes);
		if ($listener === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterListener(string $eventType): DataResponse {
		$unregistered = $this->service->unregisterEventsListener($this->request->getHeader('EX-APP-ID'), $eventType);
		if (!$unregistered) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getListener(string $eventType): DataResponse {
		$result = $this->service->getEventsListener($this->request->getHeader('EX-APP-ID'), $eventType);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
