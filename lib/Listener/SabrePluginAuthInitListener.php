<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\AppAPIAuthBackend;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-extends IEventListener<SabrePluginAuthInitListener>
 */
class SabrePluginAuthInitListener implements IEventListener {
	private AppAPIAuthBackend $authBackend;

	public function __construct(AppAPIAuthBackend $authBackend) {
		$this->authBackend = $authBackend;
	}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAuthInitEvent) {
			return;
		}

		$server = $event->getServer();
		$authPlugin = $server->getPlugin('auth');
		if ($authPlugin instanceof \Sabre\DAV\Auth\Plugin) {
			$authPlugin->addBackend($this->authBackend);
		}
	}
}
