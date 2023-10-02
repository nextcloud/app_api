<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\Service\ExAppUsersService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Remove ExApp users records of deleted User
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	private LoggerInterface $logger;
	private ExAppUsersService $exAppUsersService;

	public function __construct(LoggerInterface $logger, ExAppUsersService $exAppUsersService) {
		$this->logger = $logger;
		$this->exAppUsersService = $exAppUsersService;
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		// Delete ExApp user record on user deletion
		try {
			$this->exAppUsersService->removeDeletedUser($event->getUser()->getUID());
		} catch (\Exception $e) {
			// Ignore exceptions
			$this->logger->info('Could not delete ExApp user ' . $event->getUser()->getUID(), [
				'exception' => $e,
			]);
		}
	}
}
