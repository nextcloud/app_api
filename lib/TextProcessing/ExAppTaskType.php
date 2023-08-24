<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\TextProcessing;

use OCA\AppEcosystemV2\Db\ExFilesActionsMenu;
use OCA\AppEcosystemV2\Service\ExFilesActionsMenuService;
use OCA\AppEcosystemV2\Service\TextProcessingService;
use OCP\IL10N;
use OCP\TextProcessing\ITaskType;

class ExAppTaskType implements ITaskType {
	private IL10N $l10n;
	private ExFilesActionsMenuService $exFilesActionsMenuService;
	private TextProcessingService $textProcessingService;

	public function __construct(
		IL10N $l10n,
		ExFilesActionsMenuService $exFilesActionsMenuService,
		TextProcessingService $textProcessingService,
	) {
		$this->l10n = $l10n;
		$this->textProcessingService = $textProcessingService;
		$this->exFilesActionsMenuService = $exFilesActionsMenuService;
	}

	public function getName(): string {
		return $this->l10n->t('AppEcosystemV2 ExApp task');
	}

	public function getDescription(): string {
		$fileActions = $this->exFilesActionsMenuService->getRegisteredFileActions();
		$availableExAppProviders = join(', ', array_unique(array_map(function (ExFilesActionsMenu $fileActionMenu) {
			return $fileActionMenu->getAppid();
		}, $fileActions)));
		return $this->l10n->t(sprintf('Prompt to registered ExApp TextProcessing provider. Registered ExApps TextProcessing providers: %s. Prompt mast start with [appid]: Your prompt text', $availableExAppProviders));
	}
}
