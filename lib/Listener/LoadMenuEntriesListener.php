<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

use OCP\L10N\IFactory;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use OCP\Server;

/**
 * @template-extends IEventListener<LoadMenuEntriesListener>
 */
class LoadMenuEntriesListener implements IEventListener {

	public function __construct(
		private readonly TopMenuService $topMenuService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent) {
			return;
		}

		$menuEntries = $this->topMenuService->getExAppMenuEntries();
		if (empty($menuEntries)) {
			return;
		}

		$user = Server::get(IUserSession::class)->getUser();
		if (!$user) {
			return;
		}
		$isUserAdmin = Server::get(IGroupManager::class)->isAdmin($user->getUID());

		/** @var INavigationManager $navigationManager */
		$navigationManager = Server::get(INavigationManager::class);

		foreach ($menuEntries as $menuEntry) {
			if ($menuEntry->getAdminRequired() === 1 && !$isUserAdmin) {
				continue; // Skip this entry if the user is not an admin and the entry requires admin privileges
			}
			$navigationManager->add(static function () use ($menuEntry) {
				$appId = $menuEntry->getAppid();
				$entryName = $menuEntry->getName();
				$icon = $menuEntry->getIcon();
				$urlGenerator = Server::get(IURLGenerator::class);
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
					'name' => Server::get(IFactory::class)->get($appId)->t($menuEntry->getDisplayName()),
				];
			});
		}
	}
}
