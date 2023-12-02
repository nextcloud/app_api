<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppInitialStateService;
use OCA\AppAPI\Service\ExAppScriptsService;
use OCA\AppAPI\Service\ExAppStylesService;
use OCA\AppAPI\Service\ExFilesActionsMenuService;

use OCA\AppAPI\Service\TopMenuService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class OCSUiController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                                   $request,
		private readonly ?string                   $userId,
		private readonly ExFilesActionsMenuService $exFilesActionsMenuService,
		private readonly TopMenuService            $menuEntryService,
		private readonly ExAppInitialStateService  $initialStateService,
		private readonly ExAppScriptsService       $scriptsService,
		private readonly ExAppStylesService        $stylesService,
		private readonly AppAPIService             $appAPIService,
		private readonly IConfig                   $config,
		private readonly LoggerInterface           $logger,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param array $fileActionMenuParams [name, display_name, mime, permissions, order, icon, icon_class, action_handler]
	 *
	 * @return DataResponse
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function registerFileActionMenu(array $fileActionMenuParams): DataResponse {
		$registeredFileActionMenu = $this->exFilesActionsMenuService->registerFileActionMenu(
			$this->request->getHeader('EX-APP-ID'), $fileActionMenuParams);
		return new DataResponse([
			'success' => $registeredFileActionMenu !== null,
			'registeredFileActionMenu' => $registeredFileActionMenu,
		], Http::STATUS_OK);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileActionMenuName
	 *
	 * @throws OCSNotFoundException
	 * @return DataResponse
	 */
	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function unregisterFileActionMenu(string $fileActionMenuName): DataResponse {
		$unregisteredFileActionMenu = $this->exFilesActionsMenuService->unregisterFileActionMenu(
			$this->request->getHeader('EX-APP-ID'), $fileActionMenuName);
		if ($unregisteredFileActionMenu === null) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function getFileActionMenu(string $name): DataResponse {
		$result = $this->exFilesActionsMenuService->getExAppFileAction(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('FileActionMenu not found');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSBadRequestException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function registerExAppMenuEntry(
		string $name, string $displayName,
		string $iconUrl = '', int $adminRequired = 0): DataResponse {
		$result = $this->menuEntryService->registerExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $iconUrl, $adminRequired);
		if (!$result) {
			throw new OCSBadRequestException("Top Menu entry could not be registered");
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function unregisterExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->unregisterExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function getExAppMenuEntry(string $name): DataResponse {
		$result = $this->menuEntryService->getExAppMenuEntry(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			throw new OCSNotFoundException('No such Top Menu entry');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSBadRequestException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function setExAppInitialState(string $type, string $name, string $key, array $value): DataResponse {
		$result = $this->initialStateService->setExAppInitialState(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $key, $value);
		if (!$result) {
			throw new OCSBadRequestException("InitialState could not be set");
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function deleteExAppInitialState(string $type, string $name, string $key): DataResponse {
		$result = $this->initialStateService->deleteExAppInitialState(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $key);
		if (!$result) {
			throw new OCSNotFoundException('No such InitialState');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function getExAppInitialState(string $type, string $name, string $key): DataResponse {
		$result = $this->initialStateService->getExAppInitialState(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $key);
		if (!$result) {
			throw new OCSNotFoundException('No such InitialState');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSBadRequestException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function setExAppScript(string $type, string $name, string $path, string $afterAppId): DataResponse {
		$result = $this->scriptsService->setExAppScript(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path, $afterAppId);
		if (!$result) {
			throw new OCSBadRequestException("Script could not be set");
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function deleteExAppScript(string $type, string $name, string $path): DataResponse {
		$result = $this->scriptsService->deleteExAppScript(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Script');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function getExAppScript(string $type, string $name, string $path): DataResponse {
		$result = $this->scriptsService->getExAppScript(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Script');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSBadRequestException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function setExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->setExAppStyle(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSBadRequestException("Style could not be set");
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function deleteExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->deleteExAppStyle(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Style');
		}
		return new DataResponse();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AppAPIAuth]
	public function getExAppStyle(string $type, string $name, string $path): DataResponse {
		$result = $this->stylesService->getExAppStyle(
			$this->request->getHeader('EX-APP-ID'), $type, $name, $path);
		if (!$result) {
			throw new OCSNotFoundException('No such Style');
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $appId
	 * @param string $actionName
	 * @param array $actionFile
	 * @param string $actionHandler
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handleFileAction(string $appId, string $actionName, array $actionFile, string $actionHandler): DataResponse {
		$result = false;
		$exFileAction = $this->exFilesActionsMenuService->getExAppFileAction($appId, $actionName);
		if ($exFileAction !== null) {
			$handler = $exFileAction->getActionHandler(); // route on ex app
			$params = [
				'actionName' => $actionName,
				'actionHandler' => $actionHandler,
				'actionFile' => [
					'fileId' => $actionFile['fileId'],
					'name' => $actionFile['name'],
					'directory' => $actionFile['directory'],
					'etag' => $actionFile['etag'],
					'mime' => $actionFile['mime'],
					'fileType' => $actionFile['fileType'],
					'mtime' => $actionFile['mtime'] / 1000, // convert ms to s
					'size' => intval($actionFile['size']),
					'favorite' => $actionFile['favorite'] ?? "false",
					'permissions' => $actionFile['permissions'],
					'shareOwner' => $actionFile['shareOwner'] ?? null,
					'shareOwnerId' => $actionFile['shareOwnerId'] ?? null,
					'shareTypes' => $actionFile['shareTypes'] ?? null,
					'shareAttributes' => $actionFile['shareAttributes'] ?? null,
					'sharePermissions' => $actionFile['sharePermissions'] ?? null,
					'userId' => $this->userId,
					'instanceId' => $this->config->getSystemValue('instanceid', null),
				],
			];
			$exApp = $this->appAPIService->getExApp($appId);
			if ($exApp !== null) {
				$result = $this->appAPIService->aeRequestToExApp($exApp, $handler, $this->userId, 'POST', $params, [], $this->request);
				if ($result instanceof IResponse) {
					$result = $result->getStatusCode() === 200;
				}
				else if (isset($result['error'])) {
					$this->logger->error(sprintf('Failed to handle ExApp %s FileAction %s. Error: %s', $appId, $actionName, $result['error']));
				}
			}
		}
		return new DataResponse([
			'success' => $result,
			'handleFileActionSent' => $result,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param string $exFileActionName
	 *
	 * @return DataDisplayResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function loadFileActionIcon(string $appId, string $exFileActionName): DataDisplayResponse {
		$icon = $this->exFilesActionsMenuService->loadFileActionIcon($appId, $exFileActionName);
		if ($icon !== null && isset($icon['body'], $icon['headers'])) {
			$response = new DataDisplayResponse(
				$icon['body'],
				Http::STATUS_OK,
				['Content-Type' => $icon['headers']['Content-Type'][0] ?? 'image/svg+xml']
			);
			$response->cacheFor(ExFilesActionsMenuService::ICON_CACHE_TTL, false, true);
			return $response;
		}
		return new DataDisplayResponse('', 400);
	}
}
