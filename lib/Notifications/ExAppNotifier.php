<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Notifications;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class ExAppNotifier implements INotifier {
	public const FILE_ACTION_MENU_ALERT = 'file_action_menu_alert';
	private IFactory $factory;
	private IURLGenerator $url;
	private AppEcosystemV2Service $service;
	private ExFilesActionsMenuService $exFilesActionsMenuService;

	public function __construct(
		IFactory $factory,
		IURLGenerator $urlGenerator,
		AppEcosystemV2Service $service,
		ExFilesActionsMenuService $exFilesActionsMenuService,
	) {
		$this->factory = $factory;
		$this->url = $urlGenerator;
		$this->service = $service;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->factory->get(Application::APP_ID)->t('AppEcosystemV2 ExApp notifier');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$exApps = $this->service->getExAppsList();
		if (!in_array($notification->getApp(), $exApps)) {
			throw new \InvalidArgumentException();
		}

		switch($notification->getSubject()) {
			case self::FILE_ACTION_MENU_ALERT:
				$subjectParameters = $notification->getSubjectParameters();
				$exApp = $this->service->getExApp($notification->getApp());
				if ($exApp === null) {
					throw new \InvalidArgumentException();
				}
				// Only enabled ExApps can render notifications
				if (!$exApp->getEnabled()) {
					throw new \InvalidArgumentException('ExApp is disabled');
				}
				$fileActionMenu = $this->exFilesActionsMenuService->getExAppFileAction($notification->getApp(), $notification->getObjectId());
				if ($fileActionMenu === null) {
					throw new \InvalidArgumentException();
				}

				$linkToFile = $this->url->linkToRoute('files.view.index', ['fileid' => $subjectParameters['fileid']]);
				$notification->setLink($linkToFile);
				if ($fileActionMenu->getIcon() !== '') {
					$icon = $this->url->linkToOCSRouteAbsolute('app_ecosystem_v2.OCSApi.loadFileActionIcon', ['appId' => $notification->getApp(), 'exFileActionName' => $notification->getObjectId()]);
				} else {
					$icon = $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
				}
				$notification->setIcon($icon);

				if (isset($subjectParameters['rich_subject']) && isset($subjectParameters['rich_subject_params'])) {
					$notification->setRichSubject($subjectParameters['rich_subject'], $subjectParameters['rich_subject_params']);
				}
				if (isset($subjectParameters['rich_message']) && isset($subjectParameters['rich_message_params'])) {
					$notification->setRichMessage($subjectParameters['rich_message'], $subjectParameters['rich_message_params']);
				}

				foreach ($notification->getActions() as $action) {
					$action->setParsedLabel($action->getLabel());
					$notification->addParsedAction($action);
				}

				return $notification;

			default:
				throw new \InvalidArgumentException();
		}
	}
}
