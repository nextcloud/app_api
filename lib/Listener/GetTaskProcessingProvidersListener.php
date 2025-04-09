<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener;

use JsonException;
use OCA\AppAPI\Service\ProvidersAI\TaskProcessingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\GetTaskProcessingProvidersEvent;
use Psr\Log\LoggerInterface;

class GetTaskProcessingProvidersListener implements IEventListener {
	public function __construct(
		private readonly TaskProcessingService $taskProcessingService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event $event
	 * @return void
	 */
	public function handle(Event $event): void {
		if (!$event instanceof GetTaskProcessingProvidersEvent) {
			return;
		}

		$exAppsProviders = $this->taskProcessingService->getRegisteredTaskProcessingProviders();

		foreach ($exAppsProviders as $exAppProvider) {
			try {
				// Decode provider data
				$providerData = json_decode($exAppProvider->getProvider(), true, 512, JSON_THROW_ON_ERROR);
				$providerInstance = $this->taskProcessingService->getAnonymousExAppProvider($providerData);
				$event->addProvider($providerInstance);

				// Decode and add custom task type if it exists
				$customTaskTypeDataJson = $exAppProvider->getCustomTaskType();
				if ($customTaskTypeDataJson !== null && $customTaskTypeDataJson !== '' && $customTaskTypeDataJson !== 'null') {
					$customTaskTypeData = json_decode($customTaskTypeDataJson, true, 512, JSON_THROW_ON_ERROR);
					$taskTypeInstance = $this->taskProcessingService->getAnonymousTaskType($customTaskTypeData);
					$event->addTaskType($taskTypeInstance);
				}
			} catch (JsonException $e) {
				$this->logger->error(
					'Failed to decode or process ExApp TaskProcessing provider/task type during event handling',
					[
						'exAppId' => $exAppProvider->getAppId(),
						'providerName' => $exAppProvider->getName(),
						'exception' => $e->getMessage(),
					]
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Unexpected error processing ExApp TaskProcessing provider/task type during event handling',
					[
						'exAppId' => $exAppProvider->getAppId(),
						'providerName' => $exAppProvider->getName(),
						'exception' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]
				);
			}
		}
	}
}
