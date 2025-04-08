<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\TopMenu;
use OCA\AppAPI\Db\UI\TopMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class TopMenuService {
	private ?ICache $cache = null;

	public function __construct(
		private readonly TopMenuMapper       $mapper,
		private readonly LoggerInterface     $logger,
		private readonly InitialStateService $initialStateService,
		private readonly ScriptsService      $scriptsService,
		private readonly StylesService       $stylesService,
		ICacheFactory                        $cacheFactory,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_top_menus');
		}
	}

	public function registerExAppMenuEntry(string $appId, string $name, string $displayName,
		string $icon, int $adminRequired): ?TopMenu {
		$menuEntry = $this->getExAppMenuEntry($appId, $name);
		try {
			$newMenuEntry = new TopMenu([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'icon' => ltrim($icon, '/'),
				'admin_required' => $adminRequired,
			]);
			if ($menuEntry !== null) {
				$newMenuEntry->setId($menuEntry->getId());
			}
			$menuEntry = $this->mapper->insertOrUpdate($newMenuEntry);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s TopMenu %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $menuEntry;
	}

	public function unregisterExAppMenuEntry(string $appId, string $name): bool {
		$result = $this->mapper->removeByAppIdName($appId, $name);
		if (!$result) {
			return false;
		}
		$this->resetCacheEnabled();
		$this->initialStateService->deleteExAppInitialStatesByTypeName($appId, 'top_menu', $name);
		$this->scriptsService->deleteExAppScriptsByTypeName($appId, 'top_menu', $name);
		$this->stylesService->deleteExAppStylesByTypeName($appId, 'top_menu', $name);
		return true;
	}

	public function unregisterExAppMenuEntries(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function getExAppMenuEntry(string $appId, string $name): ?TopMenu {
		foreach ($this->getExAppMenuEntries() as $menuEntry) {
			if (($menuEntry->getAppid() === $appId) && ($menuEntry->getName() === $name)) {
				return $menuEntry;
			}
		}
		try {
			return $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Get list of registered TopMenu entries (only for enabled ExApps)
	 *
	 * @return TopMenu[]
	 */
	public function getExAppMenuEntries(): array {
		try {
			$cacheKey = '/ex_top_menus';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new TopMenu($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_top_menus');
	}
}
