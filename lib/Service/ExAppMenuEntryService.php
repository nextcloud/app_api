<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\UI\MenuEntry;
use OCA\AppAPI\Db\UI\MenuEntryMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\IAppContainer;
use OCP\DB\Exception;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ExAppMenuEntryService {
	public const ICON_CACHE_TTL = 60 * 60 * 24; // 1 day

	public function __construct(
		private MenuEntryMapper $mapper,
		private LoggerInterface $logger,
		private AppAPIService $service,
	) {
	}

	public function registerMenuEntries(IAppContainer $container): void {
		$enabledEntries = $this->mapper->findAllEnabled();
		/** @var MenuEntry $menuEntry */
		foreach ($enabledEntries as $menuEntry) {
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
					'id' => $menuEntry['appid'] . $menuEntry['route'],
					'href' => $urlGenerator->linkToRoute('app_api.MenuEntry.viewExAppPage', ['appId' => $menuEntry['appid'], 'name' => $menuEntry['name']]),
					'icon' => $menuEntry['icon_url'] === '' ? $urlGenerator->imagePath('app_api', 'app.svg') : $urlGenerator->linkToRoute('app_api.MenuEntry.ExAppIconProxy', ['appId' => $menuEntry['appid'], 'name' => $menuEntry['name']]),
					'name' => $menuEntry['display_name'],
				];
			});
		}
	}

	public function registerExAppMenuEntry(string $appId, array $params) {
		//	TODO: Register new MenuEntry from ExApp
	}

	public function unregisterExAppMenuEntry(string $appId, string $route) {
		//	TODO: Unregister ExApp MenuEntry by route
	}

	public function getExAppMenuEntry(string $appId, string $name): ?MenuEntry {
		try {
			// TODO: Add caching
			return $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error($e->getMessage());
			return null;
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
