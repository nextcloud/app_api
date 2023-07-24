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

		$l = $this->factory->get(Application::APP_ID, $languageCode);

		switch($notification->getSubject()) {
			case self::FILE_ACTION_MENU_ALERT:
				$subjectParameters = $notification->getSubjectParameters();
				$exApp = $this->service->getExApp($notification->getApp());
				if ($exApp === null) {
					throw new \InvalidArgumentException();
				}
				$fileActionMenu = $this->exFilesActionsMenuService->getExAppFileAction($notification->getApp(), $notification->getObjectId());
				if ($fileActionMenu === null) {
					throw new \InvalidArgumentException();
				}

				$notification->setLink($this->url->linkToRoute('files.view.index', ['fileid' => $subjectParameters['fileid']]));
				if ($fileActionMenu->getIcon() !== '') {
					$icon = $this->url->linkToOCSRouteAbsolute('app_ecosystem_v2.OCSApi.loadFileActionIcon', ['appId' => $notification->getApp(), 'exFileActionName' => $notification->getObjectId()]);
				} else {
					$icon = $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
				}
				$notification->setIcon($icon);
				$notification->setRichSubject($exApp->getName());
				$notification->setRichMessage($l->t($subjectParameters['message']));
				$notification->setParsedSubject($l->t($subjectParameters['message']));
				return $notification;

			default:
				throw new \InvalidArgumentException();
		}
	}
}
