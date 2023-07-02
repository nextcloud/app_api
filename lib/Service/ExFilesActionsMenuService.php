<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Service;

use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCA\AppEcosystemV2\AppInfo\Application;
use OCP\Cache\CappedMemoryCache;

use OCA\AppEcosystemV2\Db\ExFilesActionsMenu;
use OCA\AppEcosystemV2\Db\ExFilesActionsMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

class ExFilesActionsMenuService {
	private ICache $cache;
	private ExFilesActionsMenuMapper $mapper;
	private LoggerInterface $logger;
	private IClient $client;
	private AppEcosystemV2Service $appEcosystemV2Service;
	private IRequest $request;

	public function __construct(
		ICacheFactory $cacheFactory,
		ExFilesActionsMenuMapper $mapper,
		LoggerInterface $logger,
		IClientService $clientService,
		AppEcosystemV2Service $appEcosystemV2Service,
		IRequest $request,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_files_actions_menu');
		$this->mapper = $mapper;
		$this->logger = $logger;
		$this->client = $clientService->newClient();
		$this->appEcosystemV2Service = $appEcosystemV2Service;
		$this->request = $request;
	}

	/**
	 * Register file action menu from ex app
	 *
	 * @param string $appId
	 * @param array $params
	 *
	 * @return Entity|null
	 */
	public function registerFileActionMenu(string $appId, array $params): ?Entity {
		try {
			$fileActionMenu = $this->mapper->findByName($params['name']);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$fileActionMenu = null;
		}
		// If exists - update, else - create
		if ($fileActionMenu instanceof ExFilesActionsMenu) {
			$fileActionMenu->setAppid($appId);
			$fileActionMenu->setName($params['name']);
			$fileActionMenu->setDisplayName($params['display_name']);
			$fileActionMenu->setMime($params['mime']);
			$fileActionMenu->setPermissions($params['permissions']);
			$fileActionMenu->setOrder($params['order']);
			$fileActionMenu->setIcon($params['icon']);
			$fileActionMenu->setIconClass($params['icon_class']);
			$fileActionMenu->setActionHandler($params['action_handler']);
			try {
				if ($this->mapper->updateFileActionMenu($fileActionMenu) !== 1) {
					$this->logger->error(sprintf('Failed to update file action menu %s for app: %s',$params['name'], $appId));
					return null;
				}
			} catch (Exception) {
				return null;
			}
		} else {
			try {
				$fileActionMenu = $this->mapper->insert(new ExFilesActionsMenu([
					'appid' => $appId,
					'name' => $params['name'],
					'display_name' => $params['display_name'],
					'mime' => $params['mime'],
					'permissions' => $params['permissions'],
					'order' => $params['order'],
					'icon' => $params['icon'],
					'icon_class' => $params['icon_class'],
					'action_handler' => $params['action_handler'],
				]));
			} catch (Exception) {
				$this->logger->error(sprintf('Failed to insert file action menu %s for app: %s', $params['name'],$appId));
				return null;
			}
		}
		return $fileActionMenu;
	}

	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName): ?ExFilesActionsMenu {
		try {
			$fileActionMenu = $this->mapper->findByName($fileActionMenuName);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$fileActionMenu = null;
		}
		if ($fileActionMenu !== null) {
			try {
				if ($this->mapper->deleteByAppidName($fileActionMenu) !== 1) {
					$this->logger->error('Failed to delete file action menu ' . $fileActionMenuName . ' for app: ' . $appId);
					return null;
				}
			} catch (Exception) {
				$this->logger->error('Failed to delete file action menu ' . $fileActionMenuName . ' for app: ' . $appId);
				return null;
			}
		}
		return $fileActionMenu;
	}

	/**
	 * @return ExFilesActionsMenu[]|null
	 */
	public function getRegisteredFileActions(): ?array {
		$cacheKey = 'ex_files_actions_menus';
//		$cache = $this->cache->get($cacheKey);
//		if ($cache !== null) {
//			return $cache;
//		}

		try {
			$fileActions = $this->mapper->findAllEnabled();
			$this->cache->set($cacheKey, $fileActions, Application::CACHE_TTL);
			return $fileActions;
		} catch (Exception) {
			return null;
		}
	}

	public function getExAppFileAction(string $appId, string $fileActionName): ?ExFilesActionsMenu {
		$cacheKey = 'ex_files_actions_menu_' . $appId . '_' . $fileActionName;
//		$cache = $this->cache->get($cacheKey);
//		if ($cache !== null) {
//			return $cache;
//		}

		try {
			$fileAction = $this->mapper->findByAppIdName($appId, $fileActionName);
			$this->cache->set($cacheKey, $fileAction, Application::CACHE_TTL);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error(sprintf('Failed to get file action %s for app: %s', $fileActionName, $appId));
			$fileAction = null;
		}
		return $fileAction;
	}

	public function handleFileAction(string $userId, string $appId, string $fileActionName, string $actionHandler, array $actionFile): bool {
		try {
			$exFileAction = $this->mapper->findByName($fileActionName);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exFileAction = null;
		}
		if ($exFileAction !== null) {
			$handler = $exFileAction->getActionHandler(); // route on ex app
			$params = [
				'actionName' => $fileActionName,
				'actionHandler' => $actionHandler,
				'actionFile' => [
					'fileId' => $actionFile['fileId'],
					'name' => $actionFile['name'],
					'dir' => $actionFile['dir'],
				],
			];
			$exApp = $this->appEcosystemV2Service->getExApp($appId);
			if ($exApp !== null) {
				$result = $this->appEcosystemV2Service->aeRequestToExApp($this->request, $userId, $exApp, $handler, 'POST', $params);
				if ($result instanceof IResponse) {
					return $result->getStatusCode() === 200;
				}
				if (isset($result['error'])) {
					$this->logger->error(sprintf('Failed to handle file action %s for EXApp: %s with error: %s', $fileActionName, $appId, $result['error']));
					return false;
				}
			}
		}
		$this->logger->error(sprintf('Failed to find file action menu %s for ExApp: %s', $fileActionName, $appId));
		return false;
	}

	/**
	 * @param string $appId
	 * @param string $exFileActionName
	 *
	 * @return array|null
	 */
	public function loadFileActionIcon(string $appId, string $exFileActionName): ?array {
		$exFileAction = $this->getExAppFileAction($appId, $exFileActionName);
		if ($exFileAction === null) {
			return null;
		}
		$iconUrl = $exFileAction->getIcon();
		if (!isset($iconUrl) || $iconUrl === '') {
			return null;
		}
		try {
			$thumbnailResponse = $this->client->get($iconUrl);
			if ($thumbnailResponse->getStatusCode() === Http::STATUS_OK) {
				return [
					'body' => $thumbnailResponse->getBody(),
					'headers' => $thumbnailResponse->getHeaders(),
				];
			}
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Failed to load file action icon %s for ExApp: %s with error: %s', $exFileActionName, $appId, $e->getMessage()));
			return null;
		}
		return null;
	}
}
