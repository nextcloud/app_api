<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\ExFilesActionsMenuService;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-extends IEventListener<LoadFilesPluginListener>
 */
class LoadFilesPluginListener implements IEventListener {

	public function __construct(
		private IInitialState $initialState,
		private ExFilesActionsMenuService $service
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		$exFilesActions = $this->service->getRegisteredFileActions();
		if (!empty($exFilesActions)) {
			$this->initialState->provideInitialState('ex_files_actions_menu', ['fileActions' => $exFilesActions]);
			Util::addScript(Application::APP_ID, Application::APP_ID . '-filesplugin');
			Util::addStyle(Application::APP_ID, 'filesactions');
		}
	}
}
