<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\UI\FilesActionsMenuService;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/**
 * @template-extends IEventListener<LoadFilesPluginListener>
 */
class LoadFilesPluginListener implements IEventListener {

	public function __construct(
		private IInitialState $initialState,
		private FilesActionsMenuService $service,
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		$exFilesActions = $this->service->getRegisteredFileActions();
		if (!empty($exFilesActions)) {
			$this->initialState->provideInitialState('ex_files_actions_menu', [
				'fileActions' => $exFilesActions,
				'instanceId' => $this->config->getSystemValue('instanceid'),
			]);
			$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
			if (version_compare($ncVersion, '28.0', '<')) {
				Util::addScript(Application::APP_ID, Application::APP_ID . '-filesplugin');
			} else {
				Util::addScript(Application::APP_ID, Application::APP_ID . '-filesplugin28');
			}
			Util::addStyle(Application::APP_ID, 'filesactions');
		}
	}
}
