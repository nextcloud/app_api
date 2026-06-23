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
use OCA\AppAPI\Service\ExAppOccService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type AppAPIOccCommand from ResponseDefinitions
 */
class OccCommandController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly ExAppOccService $service,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Register an occ command for the calling ExApp
	 *
	 * @param string $name Name of the command
	 * @param string $description Description of the command
	 * @param string $execute_handler Handler route called when the command is executed
	 * @param int $hidden Whether the command is hidden from the command list (1) or not (0)
	 * @param list<mixed> $arguments Command argument definitions
	 * @param list<mixed> $options Command option definitions
	 * @param list<mixed> $usages Example usages of the command
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, list<empty>, array{}>
	 *
	 * 200: Command registered
	 * 400: Command could not be registered
	 */
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
			$this->request->getHeader('ex-app-id'), $name,
			$description, $hidden, $arguments, $options, $usages, $execute_handler
		);
		if ($command === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	/**
	 * Unregister an occ command of the calling ExApp
	 *
	 * @param string $name Name of the command to remove
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Command unregistered
	 * 404: Command not found
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterCommand(string $name): DataResponse {
		$unregistered = $this->service->unregisterCommand($this->request->getHeader('ex-app-id'), $name);
		if (!$unregistered) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}

	/**
	 * Get an occ command of the calling ExApp
	 *
	 * @param string $name Name of the command
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIOccCommand, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: Command returned
	 * 404: Command not found
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getCommand(string $name): DataResponse {
		$result = $this->service->getOccCommand($this->request->getHeader('ex-app-id'), $name);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}
}
