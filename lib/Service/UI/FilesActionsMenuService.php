<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
	private ?ICache $cache = null;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly FilesActionsMenuMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_ui_files_actions');
		}
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
	 * @param string $version
	 * @param string|null $defaultAction
	 * @return FilesActionsMenu|null
	 */
	public function registerFileActionMenu(string $appId, string $name, string $displayName, string $actionHandler,
		string $icon, string $mime, int $permissions, int $order, string $version,
		?string $defaultAction = null): ?FilesActionsMenu {
		try {
			$existing = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$existing = null;
		}
		try {
			$entity = $existing ?? new FilesActionsMenu();
			$entity->setAppid($appId);
			$entity->setName($name);
			$entity->setDisplayName($displayName);
			$entity->setActionHandler(ltrim($actionHandler, '/'));
			$entity->setIcon(ltrim($icon, '/'));
			$entity->setMime($mime);
			$entity->setPermissions((string)$permissions);
			$entity->setOrder($order);
			$entity->setVersion($version);
			$entity->setDefaultAction($defaultAction);
			$result = $existing === null ? $this->mapper->insert($entity) : $this->mapper->update($entity);
			$this->resetCacheEnabled();
			return $result;
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s FileActionMenu %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
	}

	public function unregisterFileActionMenu(string $appId, string $name): ?FilesActionsMenu {
		try {
			$fileActionMenu = $this->getExAppFileAction($appId, $name);
			if ($fileActionMenu !== null) {
				$this->mapper->delete($fileActionMenu);
				$this->resetCacheEnabled();
				return $fileActionMenu;
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s FileActionMenu %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Get list of registered file actions (only for enabled ExApps)
	 *
	 * @return FilesActionsMenu[]
	 */
	public function getRegisteredFileActions(): array {
		try {
			$cacheKey = '/ex_ui_files_actions';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new FilesActionsMenu($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppFileAction(string $appId, string $fileActionName): ?FilesActionsMenu {
		foreach ($this->getRegisteredFileActions() as $fileAction) {
			if (($fileAction->getAppid() === $appId) && ($fileAction->getName() === $fileActionName)) {
				return $fileAction;
			}
		}
		try {
			return $this->mapper->findByAppIdName($appId, $fileActionName);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function unregisterExAppFileActions(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_ui_files_actions');
	}
}
