<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\ResponseDefinitions;
use OCA\AppAPI\Service\UI\FilesActionsMenuService;
use OCA\AppAPI\Service\UI\InitialStateService;
use OCA\AppAPI\Service\UI\ScriptsService;
use OCA\AppAPI\Service\UI\StylesService;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-import-type AppAPIFileAction from ResponseDefinitions
 * @psalm-import-type AppAPITopMenu from ResponseDefinitions
 * @psalm-import-type AppAPIInitialState from ResponseDefinitions
 * @psalm-import-type AppAPIScript from ResponseDefinitions
 * @psalm-import-type AppAPIStyle from ResponseDefinitions
 */
class OCSUiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly FilesActionsMenuService $filesActionsMenuService,
		private readonly TopMenuService $menuEntryService,
		private readonly InitialStateService $initialStateService,
		private readonly ScriptsService $scriptsService,
		private readonly StylesService $stylesService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * Register a files action menu entry for the calling ExApp
	 *
	 * @param string $name Unique name of the action
	 * @param string $displayName Human-readable display name
	 * @param string $actionHandler Handler route called when the action is triggered
	 * @param string $icon Icon to display next to the action
	 * @param string $mime Mimetype the action applies to
	 * @param int $permissions Required permissions on the file
	 * @param int $order Ordering of the action in the menu
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException File action menu entry could not be registered
	 *
	 * 200: File action menu entry registered
	 *
	 * @deprecated since AppAPI 2.6.0, use registerFileActionMenuV2 instead
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenu(string $name, string $displayName, string $actionHandler,
		string $icon = '', string $mime = 'file', int $permissions = 31,
		int $order = 0): DataResponse {
		$result = $this->filesActionsMenuService->registerFileActionMenu(
			$this->request->getHeader('ex-app-id'), $name, $displayName, $actionHandler, $icon, $mime, $permissions, $order, '1.0');
		if (!$result) {
			throw new OCSBadRequestException('File Action Menu entry could not be registered');
		}
		return new DataResponse();
	}

	/**
	 * Register a files action menu entry (v2) for the calling ExApp
	 *
	 * @param string $name Unique name of the action
	 * @param string $displayName Human-readable display name
	 * @param string $actionHandler Handler route called when the action is triggered
	 * @param string $icon Icon to display next to the action
	 * @param string $mime Mimetype the action applies to
	 * @param int $permissions Required permissions on the file
	 * @param int $order Ordering of the action in the menu
	 * @param string $defaultAction Whether this is the default action: one of '', 'default' or 'hidden'
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException File action menu entry could not be registered or defaultAction is invalid
	 *
	 * 200: File action menu entry registered
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenuV2(string $name, string $displayName, string $actionHandler,
		string $icon = '', string $mime = 'file', int $permissions = 31,
		int $order = 0, string $defaultAction = ''): DataResponse {
		if (!in_array($defaultAction, ['', 'default', 'hidden'], true)) {
			throw new OCSBadRequestException("Invalid defaultAction '$defaultAction' — must be '', 'default', or 'hidden'");
		}
		$result = $this->filesActionsMenuService->registerFileActionMenu(
			$this->request->getHeader('ex-app-id'), $name, $displayName, $actionHandler, $icon, $mime, $permissions, $order, '2.0', $defaultAction ?: null);
		if (!$result) {
			throw new OCSBadRequestException('File Action Menu entry could not be registered');
		}
		return new DataResponse();
	}

	/**
	 * Unregister a files action menu entry of the calling ExApp
	 *
	 * @param string $name Name of the action to remove
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException File action menu entry not found
	 *
	 * 200: File action menu entry unregistered
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterFileActionMenu(string $name): DataResponse {
		$unregisteredFileActionMenu = $this->filesActionsMenuService->unregisterFileActionMenu(
			$this->request->getHeader('ex-app-id'), $name);
		if ($unregisteredFileActionMenu === null) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse();
	}

	/**
	 * Get a files action menu entry of the calling ExApp
	 *
	 * @param string $name Name of the action
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIFileAction, array{}>
	 * @throws OCSNotFoundException File action menu entry not found
	 *
	 * 200: File action menu entry returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getFileActionMenu(string $name): DataResponse {
		$result = $this->filesActionsMenuService->getExAppFileAction(
			$this->request->getHeader('ex-app-id'), $name);
		if (!$result) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}

	/**
	 * Register a top menu entry for the calling ExApp
	 *
	 * @param string $name Unique name of the menu entry
	 * @param string $displayName Human-readable display name
	 * @param string $icon Icon to display for the menu entry
	 * @param int $adminRequired Whether the entry is only visible to admins (1) or to everyone (0)
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Top menu entry could not be registered
	 *
	 * 200: Top menu entry registered
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerExAppMenuEntry(
		string $name, string $displayName,
		string $icon = '', int $adminRequired = 0): DataResponse {
		$result = $this->menuEntryService->registerExAppMenuEntry(
			$this->request->getHeader('ex-app-id'), $name, $displayName, $icon, $adminRequired);
		if (!$result) {
			throw new OCSBadRequestException('Top Menu entry could not be registered');
		}
		return new DataResponse();
	}

	/**
	 * Unregister a top menu entry of the calling ExApp
	 *
	 * @param string $name Name of the menu entry to remove
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException Top menu entry not found
	 *
	 * 200: Top menu entry unregistered
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->unregisterExAppMenuEntry(
			$this->request->getHeader('ex-app-id'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse();
	}

	/**
	 * Get a top menu entry of the calling ExApp
	 *
	 * @param string $name Name of the menu entry
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPITopMenu, array{}>
	 * @throws OCSNotFoundException Top menu entry not found
	 *
	 * 200: Top menu entry returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->getExAppMenuEntry(
			$this->request->getHeader('ex-app-id'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}

	/**
	 * Set an initial state for a page of the calling ExApp
	 *
	 * @param string $type Page type the initial state belongs to
	 * @param string $name Name of the page
	 * @param string $key Initial state key
	 * @param array<string, mixed> $value Initial state value
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Initial state could not be set
	 *
	 * 200: Initial state set
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setExAppInitialState(string $type, string $name, string $key, array $value): DataResponse {
		$result = $this->initialStateService->setExAppInitialState(
			$this->request->getHeader('ex-app-id'), $type, $name, $key, $value);
		if (!$result) {
			throw new OCSBadRequestException('InitialState could not be set');
		}
		return new DataResponse();
	}

	/**
	 * Delete an initial state of the calling ExApp
	 *
	 * @param string $type Page type the initial state belongs to
	 * @param string $name Name of the page
	 * @param string $key Initial state key
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException Initial state not found
	 *
	 * 200: Initial state deleted
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteExAppInitialState(string $type, string $name, string $key): DataResponse {
		$result = $this->initialStateService->deleteExAppInitialState(
			$this->request->getHeader('ex-app-id'), $type, $name, $key);
		if (!$result) {
			throw new OCSNotFoundException('No such InitialState');
		}
		return new DataResponse();
	}

	/**
	 * Get an initial state of the calling ExApp
	 *
	 * @param string $type Page type the initial state belongs to
	 * @param string $name Name of the page
	 * @param string $key Initial state key
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIInitialState, array{}>
	 * @throws OCSNotFoundException Initial state not found
	 *
	 * 200: Initial state returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppInitialState(string $type, string $name, string $key): DataResponse {
		$result = $this->initialStateService->getExAppInitialState(
			$this->request->getHeader('ex-app-id'), $type, $name, $key);
		if (!$result) {
			throw new OCSNotFoundException('No such InitialState');
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}

	/**
	 * Register a script for a page of the calling ExApp
	 *
	 * @param string $type Page type the script belongs to
	 * @param string $name Name of the page
	 * @param string $path Path to the script file inside the ExApp
	 * @param string $afterAppId Load the script after the script of this app
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Script could not be set
	 *
	 * 200: Script registered
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setExAppScript(string $type, string $name, string $path, string $afterAppId = ''): DataResponse {
		$result = $this->scriptsService->setExAppScript(
			$this->request->getHeader('ex-app-id'), $type, $name, $path, $afterAppId);
		if (!$result) {
			throw new OCSBadRequestException('Script could not be set');
		}
		return new DataResponse();
	}

	/**
	 * Delete a script of the calling ExApp
	 *
	 * @param string $type Page type the script belongs to
	 * @param string $name Name of the page
	 * @param string $path Path to the script file inside the ExApp
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException Script not found
	 *
	 * 200: Script deleted
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteExAppScript(string $type, string $name, string $path): DataResponse {
		$result = $this->scriptsService->deleteExAppScript(
			$this->request->getHeader('ex-app-id'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Script');
		}
		return new DataResponse();
	}

	/**
	 * Get a script of the calling ExApp
	 *
	 * @param string $type Page type the script belongs to
	 * @param string $name Name of the page
	 * @param string $path Path to the script file inside the ExApp
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIScript, array{}>
	 * @throws OCSNotFoundException Script not found
	 *
	 * 200: Script returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppScript(string $type, string $name, string $path): DataResponse {
		$result = $this->scriptsService->getExAppScript(
			$this->request->getHeader('ex-app-id'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Script');
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}

	/**
	 * Register a style for a page of the calling ExApp
	 *
	 * @param string $type Page type the style belongs to
	 * @param string $name Name of the page
	 * @param string $path Path to the style file inside the ExApp
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Style could not be set
	 *
	 * 200: Style registered
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->setExAppStyle(
			$this->request->getHeader('ex-app-id'), $type, $name, $path);
		if (!$result) {
			throw new OCSBadRequestException('Style could not be set');
		}
		return new DataResponse();
	}

	/**
	 * Delete a style of the calling ExApp
	 *
	 * @param string $type Page type the style belongs to
	 * @param string $name Name of the page
	 * @param string $path Path to the style file inside the ExApp
	 *
	 * @return DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSNotFoundException Style not found
	 *
	 * 200: Style deleted
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->deleteExAppStyle(
			$this->request->getHeader('ex-app-id'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Style');
		}
		return new DataResponse();
	}

	/**
	 * Get a style of the calling ExApp
	 *
	 * @param string $type Page type the style belongs to
	 * @param string $name Name of the page
	 * @param string $path Path to the style file inside the ExApp
	 *
	 * @return DataResponse<Http::STATUS_OK, AppAPIStyle, array{}>
	 * @throws OCSNotFoundException Style not found
	 *
	 * 200: Style returned
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->getExAppStyle(
			$this->request->getHeader('ex-app-id'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Style');
		}
		return new DataResponse($result->jsonSerialize(), Http::STATUS_OK);
	}
}
