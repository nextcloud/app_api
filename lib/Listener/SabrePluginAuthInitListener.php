<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Listener;

use OCA\AppEcosystemV2\AEAuthBackend;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-extends IEventListener<SabrePluginAuthInitListener>
 */
class SabrePluginAuthInitListener implements IEventListener {
	private AEAuthBackend $aeAuth;

	public function __construct(AEAuthBackend $aeAuth) {
		$this->aeAuth = $aeAuth;
	}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAuthInitEvent) {
			return;
		}

		$server = $event->getServer();
		$authPlugin = $server->getPlugin('auth');
		if ($authPlugin instanceof \Sabre\DAV\Auth\Plugin) {
			$authPlugin->addBackend($this->aeAuth);
		}
	}
}
