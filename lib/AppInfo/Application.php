<?php

declare(strict_types=1);

namespace OCA\AppAPI\AppInfo;

use OCA\AppAPI\Capabilities;
use OCA\AppAPI\DavPlugin;
use OCA\AppAPI\Event\ExAppInitializedEvent;
use OCA\AppAPI\Listener\ExAppInitializedListener;
use OCA\AppAPI\Listener\LoadFilesPluginListener;
use OCA\AppAPI\Listener\SabrePluginAuthInitListener;
use OCA\AppAPI\Listener\UserDeletedListener;
use OCA\AppAPI\Middleware\AppAPIAuthMiddleware;
use OCA\AppAPI\Notifications\ExAppAdminNotifier;
use OCA\AppAPI\Notifications\ExAppNotifier;
use OCA\AppAPI\Profiler\AppAPIDataCollector;
use OCA\AppAPI\PublicCapabilities;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Profiler\IProfiler;
use OCP\SabrePluginEvent;

use OCP\User\Events\UserDeletedEvent;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'app_api';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$this->registerDavAuth();
	}

	/**
	 * @psalm-suppress UndefinedClass
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesPluginListener::class);
		$context->registerCapability(Capabilities::class);
		$context->registerCapability(PublicCapabilities::class);
		$context->registerMiddleware(AppAPIAuthMiddleware::class);
		$context->registerEventListener(SabrePluginAuthInitEvent::class, SabrePluginAuthInitListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(ExAppInitializedEvent::class, ExAppInitializedListener::class);
		$context->registerNotifierService(ExAppNotifier::class);
		$context->registerNotifierService(ExAppAdminNotifier::class);
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();
		try {
			$profiler = $server->get(IProfiler::class);
			if ($profiler->isEnabled()) {
				$profiler->add(new AppAPIDataCollector());
			}
			$context->injectFn($this->registerExAppsManagementNavigation(...));
		} catch (NotFoundExceptionInterface|ContainerExceptionInterface|\Throwable) {
		}
	}

	public function registerDavAuth(): void {
		$container = $this->getContainer();

		$dispatcher = $container->query(IEventDispatcher::class);
		$dispatcher->addListener('OCA\DAV\Connector\Sabre::addPlugin', function (SabrePluginEvent $event) use ($container) {
			$event->getServer()->addPlugin($container->query(DavPlugin::class));
		});
	}

	/**
	 * Register ExApps management navigation entry right after default Apps management link.
	 *
	 * @param IUserSession $userSession
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 *
	 * @return void
	 */
	private function registerExAppsManagementNavigation(IUserSession $userSession): void {
		$container = $this->getContainer();
		/** @var IGroupManager $groupManager */
		$groupManager = $container->get(IGroupManager::class);
		/** @var IUser $user */
		$user = $userSession->getUser();
		if ($groupManager->isInGroup($user->getUID(), 'admin')) {
			$container->get(INavigationManager::class)->add(function () use ($container) {
				$urlGenerator = $container->get(IURLGenerator::class);
				$l10n = $container->get(IL10N::class);
				return [
					'id' => self::APP_ID,
					'type' => 'settings',
					'order' => 6,
					'href' => $urlGenerator->linkToRoute('app_api.ExAppsPage.viewApps'),
					'icon' => $urlGenerator->imagePath('app_api', 'app-dark.svg'),
					'target' => '_blank',
					'name' => $l10n->t('External Apps'),
				];
			});
		}
	}
}
