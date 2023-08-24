<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\TextProcessing;

use OCP\IL10N;
use OCP\TextProcessing\IProvider;

class TextProcessingProvider implements IProvider {
	private IL10N $l10n;

	public function __construct(IL10N $l10n) {
		$this->l10n = $l10n;
	}

	public function getName(): string {
		return $this->l10n->t('AppEcosystemV2 text processing provider');
	}

	public function getTaskType(): string {
		return ExAppTaskType::class;
	}

	public function process(string $prompt): string {
		// TODO: Pass prompt to registered ExApp

		return '';
	}
}
