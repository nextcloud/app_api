<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\AppInfo;

use OCA\AppAPI\Capabilities;
use OCA\AppAPI\DavPlugin;
use OCA\AppAPI\Listener\DeclarativeSettings\GetValueListener;
use OCA\AppAPI\Listener\DeclarativeSettings\RegisterDeclarativeSettingsListener;
use OCA\AppAPI\Listener\DeclarativeSettings\SetValueListener;
use OCA\AppAPI\Listener\GetTaskProcessingProvidersListener;
use OCA\AppAPI\Listener\LoadFilesPluginListener;
use OCA\AppAPI\Listener\LoadMenuEntriesListener;
use OCA\AppAPI\Listener\SabrePluginAuthInitListener;
use OCA\AppAPI\Middleware\AppAPIAuthMiddleware;
use OCA\AppAPI\Middleware\ExAppUIL10NMiddleware;
use OCA\AppAPI\Middleware\ExAppUiMiddleware;
use OCA\AppAPI\Notifications\ExAppNotifier;
use OCA\AppAPI\PublicCapabilities;
use OCA\AppAPI\SetupChecks\DaemonCheck;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use OCP\SabrePluginEvent;
use OCP\Settings\Events\DeclarativeSettingsGetValueEvent;
use OCP\Settings\Events\DeclarativeSettingsRegisterFormEvent;
use OCP\Settings\Events\DeclarativeSettingsSetValueEvent;
use OCP\TaskProcessing\Events\GetTaskProcessingProvidersEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'app_api';
	public const TEST_DEPLOY_APPID = 'test-deploy';
	public const TEST_DEPLOY_INFO_XML = 'https://raw.githubusercontent.com/nextcloud/test-deploy/main/appinfo/info.xml';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$this->registerDavAuth();
	}

	/**
	 * @psalm-suppress UndefinedClass
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(GetTaskProcessingProvidersEvent::class, GetTaskProcessingProvidersListener::class);
		$context->registerEventListener(LoadAdditionalEntriesEvent::class, LoadMenuEntriesListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesPluginListener::class);
		$context->registerCapability(Capabilities::class);
		$context->registerCapability(PublicCapabilities::class);
		$context->registerMiddleware(AppAPIAuthMiddleware::class);
		$context->registerMiddleware(ExAppUiMiddleware::class);
		$context->registerMiddleware(ExAppUIL10NMiddleware::class, true);
		$context->registerEventListener(SabrePluginAuthInitEvent::class, SabrePluginAuthInitListener::class);
		$context->registerNotifierService(ExAppNotifier::class);

		$context->registerEventListener(DeclarativeSettingsRegisterFormEvent::class, RegisterDeclarativeSettingsListener::class);
		$context->registerEventListener(DeclarativeSettingsGetValueEvent::class, GetValueListener::class);
		$context->registerEventListener(DeclarativeSettingsSetValueEvent::class, SetValueListener::class);

		$context->registerSetupCheck(DaemonCheck::class);
	}

	public function boot(IBootContext $context): void {
	}

	public function registerDavAuth(): void {
		$container = $this->getContainer();

		$dispatcher = $container->query(IEventDispatcher::class);
		$dispatcher->addListener('OCA\DAV\Connector\Sabre::addPlugin', function (SabrePluginEvent $event) use ($container) {
			$event->getServer()->addPlugin($container->query(DavPlugin::class));
		});
	}
}
