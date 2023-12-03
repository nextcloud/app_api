<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\FilesActionsMenu;
use OCA\AppAPI\Db\UI\FilesActionsMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExFilesActionsMenuService {
	public const ICON_CACHE_TTL = 60 * 60 * 24; // 1 day
	private ICache $cache;
	private IClient $client;

	public function __construct(
		ICacheFactory                           $cacheFactory,
		private readonly FilesActionsMenuMapper $mapper,
		private readonly LoggerInterface        $logger,
		IClientService                          $clientService,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_files_actions_menu');
		$this->client = $clientService->newClient();
	}

	/**
	 * Register file action menu from ExApp
	 *
	 * @param string $appId
	 * @param array $params
	 *
	 * @return FilesActionsMenu|null
	 */
	public function registerFileActionMenu(string $appId, array $params): ?FilesActionsMenu {
		try {
			$fileActionMenu = $this->mapper->findByName($params['name']);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$fileActionMenu = null;
		}
		try {
			$newFileActionMenu = new FilesActionsMenu([
				'appid' => $appId,
				'name' => $params['name'],
				'display_name' => $params['display_name'],
				'mime' => $params['mime'],
				'permissions' => $params['permissions'] ?? 31,
				'order' => $params['order'] ?? 0,
				'icon' => $params['icon'] ?? null,
				'icon_class' => $params['icon_class'] ?? 'icon-app-api',
				'action_handler' => $params['action_handler'],
			]);
			if ($fileActionMenu !== null) {
				$newFileActionMenu->setId($fileActionMenu->getId());
			}
			$fileActionMenu = $this->mapper->insertOrUpdate($newFileActionMenu);
			$this->cache->set('/ex_files_actions_menu_' . $appId . '_' . $params['name'], $fileActionMenu);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to register ExApp %s FileActionMenu %s. Error: %s', $appId, $params['name'], $e->getMessage()), ['exception' => $e]);
			return null;
		}
		return $fileActionMenu;
	}

	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName): ?FilesActionsMenu {
		try {
			$fileActionMenu = $this->getExAppFileAction($appId, $fileActionMenuName);
			if ($fileActionMenu === null) {
				return null;
			}
			$this->mapper->delete($fileActionMenu);
			$this->cache->remove('/ex_files_actions_menu_' . $appId . '_' . $fileActionMenuName);
			$this->resetCacheEnabled();
			return $fileActionMenu;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s FileActionMenu %s. Error: %s', $appId, $fileActionMenuName, $e->getMessage()), ['exception' => $e]);
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
			$this->logger->error(sprintf('Failed to load ExApp %s FileAction icon %s. Error: %s', $appId, $exFileActionName, $e->getMessage()), ['exception' => $e]);
			return null;
		}
		return null;
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
