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
	/** @var CappedMemoryCache */
	private $cache;

	/** @var ExFilesActionsMenuMapper */
	private $mapper;

	/** @var LoggerInterface */
	private $logger;

	/** @var IClient */
	private $client;

	/** @var AppEcosystemV2Service */
	private $appEcosystemV2Service;

	public function __construct(
		CappedMemoryCache $cache,
		ExFilesActionsMenuMapper $mapper,
		LoggerInterface $logger,
		IClientService $clientService,
		AppEcosystemV2Service $appEcosystemV2Service,
	) {
		$this->cache = $cache;
		$this->mapper = $mapper;
		$this->logger = $logger;
		$this->client = $clientService->newClient();
		$this->appEcosystemV2Service = $appEcosystemV2Service;
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
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
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
			if ($this->mapper->updateFileActionMenu($fileActionMenu) !== 1) {
				$this->logger->error('Failed to update file action menu ' . $params['name'] . ' for app: ' . $appId);
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
				$this->logger->error('Failed to insert file action menu ' . $params['name'] . ' for app: ' . $appId);
				return null;
			}
		}
		return $fileActionMenu;
	}

	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName) {
		/** @var ExFilesActionsMenu $fileActionMenu */
		$fileActionMenu = $this->mapper->findByName($fileActionMenuName);
		if ($fileActionMenu !== null) {
			if ($this->mapper->deleteByAppidName($fileActionMenu) !== 1) {
				$this->logger->error('Failed to delete file action menu ' . $fileActionMenuName . ' for app: ' . $appId);
				return null;
			}
		}
		return $fileActionMenu;
	}

	/**
	 * @return ExFilesActionsMenu[]
	 */
	public function getRegisteredFileActions(): array {
		$cacheKey = 'ex_app_file_actions';
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache;
		}

		$fileActions = $this->mapper->findAllEnabled();
		$this->cache->set($cacheKey, $fileActions, Application::CACHE_TTL);
		return $fileActions;
	}

	/**
	 * @param string $appId
	 *
	 * @return ExFilesActionsMenu[]
	 */
	public function getExAppFileActions(string $appId): array {
		$cacheKey = 'ex_app_file_actions_' . $appId;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache;
		}

		try {
			$fileActions = $this->mapper->findAllByAppId($appId);
		} catch (Exception) {
			$fileActions = [];
		}
		if (count($fileActions) > 0) {
			$this->cache->set($cacheKey, $fileActions, Application::CACHE_TTL);
		}
		return $fileActions;
	}

	public function getExAppFileAction(string $exAppId, string $fileActionName): ?ExFilesActionsMenu {
		$cacheKey = 'ex_app_file_action_' . $exAppId . '_' . $fileActionName;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache;
		}

		try {
			$fileAction = $this->mapper->findByAppIdName($exAppId, $fileActionName);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			$fileAction = null;
		}
		$this->cache->set($cacheKey, $fileAction, Application::CACHE_TTL);
		return $fileAction;
	}

	public function handleFileAction(string $userId, string $appId, string $fileActionName, string $actionHandler, array $actionFile): bool {
		try {
			$exFileAction = $this->mapper->findByName($fileActionName);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
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
				$result = $this->appEcosystemV2Service->requestToExApp($userId, $exApp, $handler, 'POST', $params);
				if ($result instanceof IResponse) {
					return $result->getStatusCode() === 200;
				}
				if (isset($result['error'])) {
					$this->logger->error('Failed to handle file action ' . $fileActionName . ' for app: ' . $appId . ' with error: ' . $result['error']);
					return false;
				}
			}
		}
		$this->logger->error('Failed to find file action menu ' . $fileActionName . ' for app: ' . $appId);
		return false;
	}

	/**
	 * @param string $exAppId
	 * @param string $exFileActionName
	 * @return array|null
	 */
	public function loadFileActionIcon(string $exAppId, string $exFileActionName): ?array {
		$exFileAction = $this->getExAppFileAction($exAppId, $exFileActionName);
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
			$this->logger->error('Failed to load file action icon ' . $exFileActionName . ' for app: ' . $exAppId . ' with error: ' . $e->getMessage());
			return null;
		}
		return null;
	}
}
