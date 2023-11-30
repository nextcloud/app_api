<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\UI\TopMenu;
use OCA\AppAPI\Db\UI\TopMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\IAppContainer;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class TopMenuService {
	public const ICON_CACHE_TTL = 60 * 60 * 24; // 1 day

	public function __construct(
		private TopMenuMapper   $mapper,
		private LoggerInterface $logger,
		private AppAPIService   $service,
		private ICache			$cache,
	) {
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws ContainerExceptionInterface
	 * @throws Exception
	 */
	public function registerMenuEntries(IAppContainer $container): void {
		/** @var TopMenu $menuEntry */
		foreach ($this->getExAppMenuEntries() as $menuEntry) {
			$userSession = $container->get(IUserSession::class);
			/** @var IGroupManager $groupManager */
			$groupManager = $container->get(IGroupManager::class);
			/** @var IUser $user */
			$user = $userSession->getUser();
			if ($menuEntry['admin_required'] === 1 && !$groupManager->isInGroup($user->getUID(), 'admin')) {
				continue; // Skip this entry if user is not admin and entry requires admin privileges
			}
			$container->get(INavigationManager::class)->add(function () use ($container, $menuEntry) {
				$urlGenerator = $container->get(IURLGenerator::class);
				return [
					'id' => $menuEntry['appid'] . '_' . $menuEntry['name'],
					'href' => $urlGenerator->linkToRoute('app_api.TopMenu.viewExAppPage', ['appId' => $menuEntry['appid'], 'name' => $menuEntry['name']]),
					'icon' => $menuEntry['icon_url'] === '' ? $urlGenerator->imagePath('app_api', 'app.svg') : $urlGenerator->linkToRoute('app_api.TopMenu.ExAppIconProxy', ['appId' => $menuEntry['appid'], 'name' => $menuEntry['name']]),
					'name' => $menuEntry['display_name'],
				];
			});
		}
	}

	public function registerExAppMenuEntry(string $appId, string $name, string $displayName,
										   string $iconUrl, int $adminRequired): ?TopMenu {
		try {
			$menuEntry = $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$menuEntry = null;
		}
		try {
			$newMenuEntry = new TopMenu([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'icon_url' => $iconUrl,
				'admin_required' => $adminRequired,
			]);
			if ($menuEntry !== null) {
				$newMenuEntry->setId($menuEntry->getId());
			}
			$menuEntry = $this->mapper->insertOrUpdate($newMenuEntry);
			$cacheKey = '/ex_top_menu_' . $appId . '_' . $name;
			$this->cache->remove('/ex_top_menus');
			$this->cache->set($cacheKey, $menuEntry);
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
		$this->cache->remove('/ex_top_menus');
		return true;
	}

	public function unregisterExAppMenuEntries(ExApp $exApp): int {
		try {
			$result = $this->mapper->removeAllByAppId($exApp->getAppid());
		} catch (Exception) {
			$result = -1;
		}
		$this->cache->clear('/ex_top_menu_' . $exApp->getAppid());
		$this->cache->remove('/ex_top_menus');
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
			$this->cache->set($cacheKey, $menuEntry);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error($e->getMessage());
			return null;
		}
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

	public function loadFileActionIcon(string $appId, string $name, ExApp $exApp, IRequest $request, string $userId): ?array {
		//		$menuEntry = $this->getExAppMenuEntry($appId, $name);
		//		if ($menuEntry === null) {
		//			return null;
		//		}
		//		$iconUrl = $menuEntry->getIconUrl();
		//		if (!isset($iconUrl) || $iconUrl === '') {
		//			return null;
		//		}
		//		try {
		//			$iconResponse = $this->service->requestToExApp($request, $userId, $exApp, $iconUrl, 'GET');
		//			if ($iconResponse->getStatusCode() === Http::STATUS_OK) {
		//				return [
		//					'body' => $iconResponse->getBody(),
		//					'headers' => $iconResponse->getHeaders(),
		//				];
		//			}
		//		} catch (\Exception $e) {
		//			$this->logger->error(sprintf('Failed to load ExApp %s MenuEntry icon %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
		//			return null;
		//		}
		return null;
	}
}
