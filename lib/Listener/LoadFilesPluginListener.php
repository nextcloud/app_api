<?php
/**
 * @copyright Copyright (c) 2023 Julien Veyssier <julien-nc@posteo.net>
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\AppEcosystemV2\Listener;

use OCP\Util;
use OCP\IConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

class LoadFilesPluginListener implements IEventListener {

	/** @var IInitialState */
	private $initialState;

	/** @var AppEcosystemV2Service */
	private $service;

	public function __construct(
		IInitialState $initialState,
		AppEcosystemV2Service $service,
	) {
		$this->initialState = $initialState;
		$this->service = $service;
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		// TODO: Select registered ex_apps ex_files_actions and attach script with provided data
		$exFilesActions = $this->service->getExFilesActions();
		if (!empty($exFilesActions)) {
			$this->initialState->provideInitialState('ex_files_actions_menu', $exFilesActions);
			Util::addScript(Application::APP_ID, Application::APP_ID . '-filesplugin');
			Util::addStyle(Application::APP_ID, 'filesplugin');
		}
	}
}
