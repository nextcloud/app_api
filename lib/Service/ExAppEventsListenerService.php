<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExAppEventsListener;
use OCA\AppAPI\Db\ExAppEventsListenerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExAppEventsListenerService {

	private ?ICache $cache = null;

	public function __construct(
		private readonly LoggerInterface     	   $logger,
		private readonly ExAppEventsListenerMapper $mapper,
		ICacheFactory                              $cacheFactory,

	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_events_listener');
		}
	}

	public function registerEventsListener(string $appId, string $eventType, string $actionHandler, array $eventSubtypes = []): ?ExAppEventsListener {
		$eventsListenerEntry = $this->getEventsListener($appId, $eventType);
		try {
			$newEventsListenerEntry = new ExAppEventsListener([
				'appid' => $appId,
				'event_type' => $eventType,
				'event_subtypes' => $eventSubtypes,
				'action_handler' => ltrim($actionHandler, '/'),
			]);
			if ($eventsListenerEntry !== null) {
				$newEventsListenerEntry->setId($eventsListenerEntry->getId());
			}
			$eventsListenerEntry = $this->mapper->insertOrUpdate($newEventsListenerEntry);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register Events listener for %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $eventsListenerEntry;
	}

	public function unregisterEventsListener(string $appId, string $eventType): bool {
		if (!$this->mapper->removeByAppIdEventType($appId, $eventType)) {
			return false;
		}
		$this->resetCacheEnabled();
		return true;
	}

	public function getEventsListener(string $appId, string $eventType): ?ExAppEventsListener {
		foreach ($this->getEventsListeners() as $eventsListener) {
			if (($eventsListener->getAppid() === $appId) && ($eventsListener->getEventType() === $eventType)) {
				return $eventsListener;
			}
		}
		try {
			return $this->mapper->findByAppIdEventType($appId, $eventType);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Get list of registered Events Listeners (only for enabled ExApps)
	 *
	 * @return ExAppEventsListener[]
	 */
	public function getEventsListeners(): array {
		try {
			$cacheKey = '/ex_events_listener';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new ExAppEventsListener($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function unregisterExAppEventListeners(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_events_listener');
	}
}
