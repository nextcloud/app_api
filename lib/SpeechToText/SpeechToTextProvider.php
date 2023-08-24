<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\SpeechToText;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCP\Files\File;
use OCP\IL10N;
use OCP\SpeechToText\ISpeechToTextProvider;

class SpeechToTextProvider implements ISpeechToTextProvider {

	private IL10N $l10n;
	private AppEcosystemV2Service $service;

	public function __construct(
		IL10N $l10n,
		AppEcosystemV2Service $service,
	) {
		$this->l10n = $l10n;
		$this->service = $service;
	}

	public function getName(): string {
		return $this->l10n->t('AppEcosystemV2 speech-to-text provider');
	}

	public function transcribeFile(File $file): string {
		// TODO: Pass request to ExApp with file params

	}
}
