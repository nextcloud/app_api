<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppEventsListenerService;
use OCA\AppAPI\Service\ExAppService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class FileEventsListener implements IEventListener {

	public function __construct(
		private readonly ExAppEventsListenerService $service,
		private readonly AppAPIService 				$appAPIService,
		private readonly ExAppService			    $exAppService,
		private readonly IConfig 					$config,
		private readonly LoggerInterface     	    $logger,
		private readonly IUserSession				$userSession,
		private readonly IRootFolder				$rootFolder,
	) {
	}

	public function handle(Event $event): void {
		$filteredNodeEventListeners = array_filter($this->service->getEventsListeners(), function ($eventsListener) {
			return $eventsListener->getEventType() === 'node_event';
		});
		if (empty($filteredNodeEventListeners)) {
			return;
		}
		$eventSubtype = '';
		if ($event instanceof NodeCreatedEvent) {
			$eventSubtype = 'NodeCreatedEvent';
		} elseif ($event instanceof NodeTouchedEvent) {
			$eventSubtype = 'NodeTouchedEvent';
		} elseif ($event instanceof NodeWrittenEvent) {
			$eventSubtype = 'NodeWrittenEvent';
		} elseif ($event instanceof NodeDeletedEvent) {
			$eventSubtype = 'NodeDeletedEvent';
		} elseif ($event instanceof NodeRenamedEvent) {
			$eventSubtype = 'NodeRenamedEvent';
		} elseif ($event instanceof NodeCopiedEvent) {
			$eventSubtype = 'NodeCopiedEvent';
		}
		if ($eventSubtype === '') {
			return;
		}
		$eventData = [
			'event_type' => 'node_event',
			'event_subtype' => $eventSubtype,
		];
		try {
			if (($event instanceof NodeRenamedEvent) || ($event instanceof NodeCopiedEvent)) {
				$eventData['event_data'] = [
					'target' => $this->serializeNodeInfo($event->getTarget()),
				];
				$eventData['event_data']['source'] = $this->serializeNodeInfo($event->getSource(), $eventData['event_data']['target']);
			} elseif ($event instanceof AbstractNodeEvent) {
				$eventData['event_data'] = [
					'target' => $this->serializeNodeInfo($event->getNode()),
				];
			} else {
				return;
			}
		} catch (NotFoundException | NotPermittedException $e) {
			$this->logger->error(
				sprintf('Failed to serialize NodeInfo: %s', $e->getMessage()), ['exception' => $e]
			);
			return;
		}
		foreach ($filteredNodeEventListeners as $nodeEventListener) {
			if (
				empty($nodeEventListener->getEventSubtypes()) ||
				in_array($eventSubtype, $nodeEventListener->getEventSubtypes())
			) {
				$exApp = $this->exAppService->getExApp($nodeEventListener->getAppid());
				if ($exApp !== null) {
					$args = [
						'app_api:app:notify',
						escapeshellarg($exApp->getAppid()),
						escapeshellarg($nodeEventListener->getActionHandler()),
						'--event-json',
						escapeshellarg(json_encode($eventData))
					];
					$userId = $this->userSession->getUser();
					if ($userId !== null) {
						$args[] = '--user-id';
						$args[] = escapeshellarg($userId->getUID());
					}
					$this->appAPIService->runOccCommandInternal($args);
				}
			}
		}
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function serializeNodeInfo(Node $node, array $fallbackInfo = []): array {
		$userPath = $this->getPathForNode($node);
		$result = $fallbackInfo;
		$result['favorite'] = '';
		$result['name'] = basename($userPath);
		$result['directory'] = dirname($userPath);
		$result['instanceId'] = $this->config->getSystemValue('instanceid');
		try {
			$result['fileId'] = $node->getId();
			$result['etag'] = $node->getEtag();
			$result['mime'] = $node->getMimeType();
			$result['permissions'] = $node->getPermissions();
			$result['fileType'] = $node->getType();
			$result['size'] = $node->getSize();
			$result['mtime'] = $node->getMTime();
			$result['userId'] = $node->getOwner()->getUID();
		} catch (NotFoundException | InvalidPathException) {
		}
		return $result;
	}

	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private function getPathForNode(Node $node): ?string {
		$user = $this->userSession->getUser()?->getUID();
		if ($user) {
			$path = $this->rootFolder
				->getUserFolder($user)
				->getRelativePath($node->getPath());

			if ($path !== null) {
				return $path;
			}
		}
		$owner = $node->getOwner()?->getUid();
		if ($owner) {
			$path = $this->rootFolder
				->getUserFolder($owner)
				->getRelativePath($node->getPath());

			if ($path !== null) {
				return $path;
			}
		}
		return null;
	}
}
