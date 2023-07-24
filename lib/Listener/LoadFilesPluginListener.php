<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Listener;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-extends IEventListener<LoadFilesPluginListener>
 */
class LoadFilesPluginListener implements IEventListener {
	private IInitialState $initialState;
	private ExFilesActionsMenuService $service;

	public function __construct(
		IInitialState $initialState,
		ExFilesActionsMenuService $service
	) {
		$this->initialState = $initialState;
		$this->service = $service;
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
