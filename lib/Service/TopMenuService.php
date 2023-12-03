<?php

declare(strict_types=1);


namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\TopMenu;
use OCA\AppAPI\Db\UI\TopMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class TopMenuService {
	private ICache $cache;

	public function __construct(
		private readonly TopMenuMapper            $mapper,
		private readonly LoggerInterface          $logger,
		private readonly ExAppInitialStateService $initialStateService,
		private readonly ExAppScriptsService      $scriptsService,
		private readonly ExAppStylesService       $stylesService,
		ICacheFactory                             $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_top_menus');
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws ContainerExceptionInterface
	 * @throws Exception
	 */
	public function registerMenuEntries(ContainerInterface $container): void {
		/** @var TopMenu $menuEntry */
		foreach ($this->getExAppMenuEntries() as $menuEntry) {
			$userSession = $container->get(IUserSession::class);
			/** @var IGroupManager $groupManager */
			$groupManager = $container->get(IGroupManager::class);
			/** @var IUser $user */
			$user = $userSession->getUser();
			if ($menuEntry->getAdminRequired() === 1 && !$groupManager->isInGroup($user->getUID(), 'admin')) {
				continue; // Skip this entry if user is not admin and entry requires admin privileges
			}
			$container->get(INavigationManager::class)->add(function () use ($container, $menuEntry) {
				$urlGenerator = $container->get(IURLGenerator::class);
				$appId = $menuEntry->getAppid();
				$entryName = $menuEntry->getName();
				$icon= $menuEntry->getIcon();
				return [
					'id' => Application::APP_ID . '_' . $appId . '_' . $entryName,
					'type' => 'link',
					'app' => Application::APP_ID,
					'href' => $urlGenerator->linkToRoute(
						'app_api.TopMenu.viewExAppPage', ['appId' => $appId, 'name' => $entryName]
					),
					'icon' => $icon === '' ?
						$urlGenerator->imagePath('app_api', 'app.svg') :
						$urlGenerator->linkToRoute(
							'app_api.ExAppProxy.ExAppGet', ['appId' => $appId, 'other' => $icon]
						),
					'name' => $menuEntry->getDisplayName(),
				];
			});
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
				'icon' => $icon,
				'admin_required' => $adminRequired,
			]);
			if ($menuEntry !== null) {
				$newMenuEntry->setId($menuEntry->getId());
			}
			$menuEntry = $this->mapper->insertOrUpdate($newMenuEntry);
			$this->cache->set('/ex_top_menu_' . $appId . '_' . $name, $menuEntry);
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
		$this->cache->remove('/ex_top_menu_' . $appId . '_' . $name);
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
		$this->cache->clear('/ex_top_menu_' . $appId);
		$this->resetCacheEnabled();
		return $result;
	}

	public function getExAppMenuEntry(string $appId, string $name): ?TopMenu {
		$cacheKey = '/ex_top_menu_' . $appId . '_' . $name;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache instanceof TopMenu ? $cache : new TopMenu($cache);
		}

		try {
			$menuEntry = $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
		$this->cache->set($cacheKey, $menuEntry);
		return $menuEntry;
	}

	public function getExAppMenuEntries(): array {
		try {
			$cacheKey = '/ex_top_menus';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function ($cacheEntry) {
					return $cacheEntry instanceof TopMenu ? $cacheEntry : new TopMenu($cacheEntry);
				}, $cached);
			}
			$menuEntries = $this->mapper->findAllEnabled();
			$this->cache->set($cacheKey, $menuEntries);
			return $menuEntries;
		} catch (Exception) {
			return [];
		}
	}

	public function resetCacheEnabled(): void {
		$this->cache->remove('/ex_top_menus');
	}
}
