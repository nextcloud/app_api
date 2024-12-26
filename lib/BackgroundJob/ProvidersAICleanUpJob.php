<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\BackgroundJob;

use OCA\AppAPI\Db\SpeechToText\SpeechToTextProviderQueueMapper;
use OCA\AppAPI\Db\TextProcessing\TextProcessingProviderQueueMapper;
use OCA\AppAPI\Db\Translation\TranslationQueueMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\Exception;

class ProvidersAICleanUpJob extends TimedJob {

	private const overdueTime = 7 * 24 * 60 * 60;

	public function __construct(
		ITimeFactory                                       $time,
		private readonly TextProcessingProviderQueueMapper $mapperTextProcessing,
		private readonly SpeechToTextProviderQueueMapper   $mapperSpeechToText,
		private readonly TranslationQueueMapper            $mapperTranslation,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 60 * 60);  # run one a day
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		// Iterate over all AI Providers queues and remove results older than one week.
		// This is reinsurance job, if everything is without errors, then each provider cleans up the garbage itself.
		try {
			$this->mapperTextProcessing->removeAllOlderThenThat(self::overdueTime);
			$this->mapperSpeechToText->removeAllOlderThenThat(self::overdueTime);
			$this->mapperTranslation->removeAllOlderThenThat(self::overdueTime);
		} catch (Exception) {
		}
	}
}
