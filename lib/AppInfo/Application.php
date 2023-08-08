<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\AppInfo;

use OCA\AppEcosystemV2\Capabilities;
use OCA\AppEcosystemV2\DavPlugin;
use OCA\AppEcosystemV2\Listener\LoadFilesPluginListener;
use OCA\AppEcosystemV2\Listener\SabrePluginAuthInitListener;
use OCA\AppEcosystemV2\Middleware\AppEcosystemAuthMiddleware;
use OCA\AppEcosystemV2\Notifications\ExAppAdminNotifier;
use OCA\AppEcosystemV2\Notifications\ExAppNotifier;
use OCA\AppEcosystemV2\Profiler\AEDataCollector;
use OCA\AppEcosystemV2\PublicCapabilities;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Profiler\IProfiler;
use OCP\SabrePluginEvent;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'app_ecosystem_v2';

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
		$context->registerMiddleware(AppEcosystemAuthMiddleware::class);
		$context->registerEventListener(SabrePluginAuthInitEvent::class, SabrePluginAuthInitListener::class);
		$context->registerNotifierService(ExAppNotifier::class);
		$context->registerNotifierService(ExAppAdminNotifier::class);
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();
		try {
			$profiler = $server->get(IProfiler::class);
			if ($profiler->isEnabled()) {
				$profiler->add(new AEDataCollector());
			}
		} catch (NotFoundExceptionInterface|ContainerExceptionInterface) {
		}
	}

	public function registerDavAuth(): void {
		$container = $this->getContainer();

		$dispatcher = $container->query(IEventDispatcher::class);
		$dispatcher->addListener('OCA\DAV\Connector\Sabre::addPlugin', function (SabrePluginEvent $event) use ($container) {
			$event->getServer()->addPlugin($container->query(DavPlugin::class));
		});
	}
}
