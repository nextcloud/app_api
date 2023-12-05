<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service\UI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\FilesActionsMenu;
use OCA\AppAPI\Db\UI\FilesActionsMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class FilesActionsMenuService {
	public const ICON_CACHE_TTL = 60 * 60 * 24; // 1 day
	private ICache $cache;

	public function __construct(
		ICacheFactory                           $cacheFactory,
		private readonly FilesActionsMenuMapper $mapper,
		private readonly LoggerInterface        $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_files_actions_menu');
	}

	/**
	 * Register file action menu from ExApp
	 *
	 * @param string $appId
	 * @param string $name
	 * @param string $displayName
	 * @param string $actionHandler
	 * @param string $icon
	 * @param string $mime
	 * @param int $permissions
	 * @param int $order
	 * @return FilesActionsMenu|null
	 */
	public function registerFileActionMenu(string $appId, string $name, string $displayName, string $actionHandler,
		string $icon, string $mime, int $permissions, int $order): ?FilesActionsMenu {
		try {
			$fileActionMenu = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$fileActionMenu = null;
		}
		try {
			$newFileActionMenu = new FilesActionsMenu([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'action_handler' => ltrim($actionHandler, '/'),
				'icon' => ltrim($icon, '/'),
				'mime' => $mime,
				'permissions' => $permissions,
				'order' => $order,
			]);
			if ($fileActionMenu !== null) {
				$newFileActionMenu->setId($fileActionMenu->getId());
			}
			$fileActionMenu = $this->mapper->insertOrUpdate($newFileActionMenu);
			$this->cache->set('/ex_files_actions_menu_' . $appId . '_' . $name, $fileActionMenu);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s FileActionMenu %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $fileActionMenu;
	}

	public function unregisterFileActionMenu(string $appId, string $name): ?FilesActionsMenu {
		try {
			$fileActionMenu = $this->getExAppFileAction($appId, $name);
			if ($fileActionMenu === null) {
				return null;
			}
			$this->mapper->delete($fileActionMenu);
			$this->cache->remove('/ex_files_actions_menu_' . $appId . '_' . $name);
			$this->resetCacheEnabled();
			return $fileActionMenu;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s FileActionMenu %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Get list of registered file actions (only for enabled ExApps)
	 *
	 * @return FilesActionsMenu[]|null
	 */
	public function getRegisteredFileActions(): ?array {
		try {
			$cacheKey = '/ex_files_actions_menus';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function ($cacheEntry) {
					return $cacheEntry instanceof FilesActionsMenu ? $cacheEntry : new FilesActionsMenu($cacheEntry);
				}, $cached);
			}

			$fileActions = $this->mapper->findAllEnabled();
			$this->cache->set($cacheKey, $fileActions);
			return $fileActions;
		} catch (Exception) {
			return null;
		}
	}

	public function getExAppFileAction(string $appId, string $fileActionName): ?FilesActionsMenu {
		$cacheKey = '/ex_files_actions_menu_' . $appId . '_' . $fileActionName;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache instanceof FilesActionsMenu ? $cache : new FilesActionsMenu($cache);
		}

		try {
			$fileAction = $this->mapper->findByAppIdName($appId, $fileActionName);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
		$this->cache->set($cacheKey, $fileAction);
		return $fileAction;
	}

	public function unregisterExAppFileActions(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->cache->clear('/ex_files_actions_menu_' . $appId);
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache->remove('/ex_files_actions_menus');
	}
}
