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

namespace OCA\AppEcosystemV2\AppInfo;

use OC_User;
use OCP\IRequest;
use OCP\ISession;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\SabrePluginEvent;

use OCA\Files\Event\LoadAdditionalScriptsEvent;

use OCA\AppEcosystemV2\Capabilities;
use OCA\AppEcosystemV2\DavPlugin;
use OCA\AppEcosystemV2\Listener\LoadFilesPluginListener;
use OCA\AppEcosystemV2\Middleware\AEAuthMiddleware;

class Application extends App implements IBootstrap {
	public const APP_ID = 'app_ecosystem_v2';

	public const CACHE_TTL = 3600;
	public const ICON_CACHE_TTL = 60 * 60 *24;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$this->registerDavAuth();
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesPluginListener::class);
		$context->registerCapability(Capabilities::class);
		$context->registerMiddleware(AEAuthMiddleware::class);
	}

	public function boot(IBootContext $context): void {
	}

	public function registerDavAuth() {
		$container = $this->getContainer();

		$dispatcher = $container->getServer()->getEventDispatcher();
		$dispatcher->addListener('OCA\DAV\Connector\Sabre::addPlugin', function (SabrePluginEvent $event) use ($container) {
			$event->getServer()->addPlugin($container->query(DavPlugin::class));
		});
		OC_User::useBackend(new \OCA\AppEcosystemV2\UserBackend(
			$container->get(IRequest::class),
			$container->get(ISession::class),
		));
	}
}

