<?php

declare(strict_types=1);

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\Event\ExAppInitializedEvent;
use OCA\AppAPI\Service\AppAPIService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Enabled ExApp after initialization finished
 *
 * @template-implements IEventListener<ExAppInitializedEvent>
 */
class ExAppInitializedListener implements IEventListener {
	private LoggerInterface $logger;
	private AppAPIService $service;

	public function __construct(LoggerInterface $logger, AppAPIService $service) {
		$this->logger = $logger;
		$this->service = $service;
	}

	public function handle(Event $event): void {
		if (!($event instanceof ExAppInitializedEvent)) {
			return;
		}

		$attempts = 0;
		$totalAttempts = 3;
		$delay = 1;

		while ($attempts < $totalAttempts) {
			$attempts++;
			$exApp = $this->service->getExApp($event->getAppid());
			$status = json_decode($exApp->getStatus(), true);
			$this->logger->warning('ExApp ' . $event->getAppid() . ' status: ' . json_encode($status) );
			if (!isset($status['progress']) && !isset($status['error']) && $status['active']) {
				$this->service->enableExApp($exApp);
				return;
			}
			sleep($delay);
		}

		$this->logger->error(sprintf('Did not catch ExApp %s in initialized state to enable.', $event->getAppid()));
	}
}
