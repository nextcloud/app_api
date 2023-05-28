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

use Psr\Log\LoggerInterface;
use OCA\AppEcosystemV2\AppInfo\Application;
use OCP\Cache\CappedMemoryCache;

use OCA\AppEcosystemV2\Db\ExFilesActionsMenu;
use OCA\AppEcosystemV2\Db\ExFilesActionsMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

class ExFilesActionsMenuService {
	/** @var CappedMemoryCache */
	private $cache;

	/** @var ExFilesActionsMenuMapper */
	private $mapper;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		CappedMemoryCache $cache,
		ExFilesActionsMenuMapper $mapper,
		LoggerInterface $logger,
	) {
		$this->cache = $cache;
		$this->mapper = $mapper;
		$this->logger = $logger;
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
		} catch (DoesNotExistException) {
			$fileActionMenu = null;
		}
		// If exists - update, else - create
		if ($fileActionMenu !== null && $fileActionMenu instanceof ExFilesActionsMenu) {
			$fileActionMenu->setAppid($appId);
			$fileActionMenu->setName($params['name']);
			$fileActionMenu->setDisplayName($params['display_name']);
			$fileActionMenu->setMime($params['mime']);
			$fileActionMenu->setPermissions($params['permissions']);
			$fileActionMenu->setOrder($params['order']);
			$fileActionMenu->setIcon($params['icon']);
			$fileActionMenu->setIconClass($params['icon_class']);
			$fileActionMenu->setActionHandler($params['action_handler']);
			$this->mapper->update($fileActionMenu);
		} else {
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

		$fileActions = $this->mapper->findAll();
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

		$fileActions = $this->mapper->findByAppId($appId);
		$this->cache->set($cacheKey, $fileActions, Application::CACHE_TTL);
		return $fileActions;
	}
}
