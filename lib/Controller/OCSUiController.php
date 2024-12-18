<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
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

class OCSUiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                                 $request,
		private readonly FilesActionsMenuService $filesActionsMenuService,
		private readonly TopMenuService          $menuEntryService,
		private readonly InitialStateService     $initialStateService,
		private readonly ScriptsService          $scriptsService,
		private readonly StylesService           $stylesService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @throws OCSBadRequestException
	 *
	 * @deprecated since AppAPI 2.6.0, use registerFileActionMenuV2 instead
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenu(string $name, string $displayName, string $actionHandler,
		string $icon = "", string $mime = "file", int $permissions = 31,
		int $order = 0): DataResponse {
		$result = $this->filesActionsMenuService->registerFileActionMenu(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $actionHandler, $icon, $mime, $permissions, $order, '1.0');
		if (!$result) {
			throw new OCSBadRequestException("File Action Menu entry could not be registered");
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenuV2(string $name, string $displayName, string $actionHandler,
		string $icon = "", string $mime = "file", int $permissions = 31,
		int $order = 0): DataResponse {
		$result = $this->filesActionsMenuService->registerFileActionMenu(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $actionHandler, $icon, $mime, $permissions, $order, '2.0');
		if (!$result) {
			throw new OCSBadRequestException("File Action Menu entry could not be registered");
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterFileActionMenu(string $name): DataResponse {
		$unregisteredFileActionMenu = $this->filesActionsMenuService->unregisterFileActionMenu(
			$this->request->getHeader('EX-APP-ID'), $name);
		if ($unregisteredFileActionMenu === null) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getFileActionMenu(string $name): DataResponse {
		$result = $this->filesActionsMenuService->getExAppFileAction(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerExAppMenuEntry(
		string $name, string $displayName,
		string $icon = '', int $adminRequired = 0): DataResponse {
		$result = $this->menuEntryService->registerExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $icon, $adminRequired);
		if (!$result) {
			throw new OCSBadRequestException("Top Menu entry could not be registered");
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->unregisterExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->getExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setExAppInitialState(string $type, string $name, string $key, array $value): DataResponse {
		$result = $this->initialStateService->setExAppInitialState(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $key, $value);
		if (!$result) {
			throw new OCSBadRequestException("InitialState could not be set");
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteExAppInitialState(string $type, string $name, string $key): DataResponse {
		$result = $this->initialStateService->deleteExAppInitialState(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $key);
		if (!$result) {
			throw new OCSNotFoundException('No such InitialState');
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppInitialState(string $type, string $name, string $key): DataResponse {
		$result = $this->initialStateService->getExAppInitialState(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $key);
		if (!$result) {
			throw new OCSNotFoundException('No such InitialState');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setExAppScript(string $type, string $name, string $path, string $afterAppId = ''): DataResponse {
		$result = $this->scriptsService->setExAppScript(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path, $afterAppId);
		if (!$result) {
			throw new OCSBadRequestException("Script could not be set");
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteExAppScript(string $type, string $name, string $path): DataResponse {
		$result = $this->scriptsService->deleteExAppScript(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Script');
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppScript(string $type, string $name, string $path): DataResponse {
		$result = $this->scriptsService->getExAppScript(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Script');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @throws OCSBadRequestException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function setExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->setExAppStyle(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSBadRequestException("Style could not be set");
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->deleteExAppStyle(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Style');
		}
		return new DataResponse();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->getExAppStyle(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Style');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}
}
