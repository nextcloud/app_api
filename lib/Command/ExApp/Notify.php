<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppEventsListenerService;
use OCA\AppAPI\Service\ExAppService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Notify extends Command {
	public function __construct(
		private AppAPIService $service,
		private ExAppService $exAppService,
		private ExAppEventsListenerService $exAppEventsListenerService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:notify');
		$this->setDescription('Notify ExApp about internal event');
		$this->setHidden(true);
		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('route', InputArgument::REQUIRED);
		$this->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User ID');
		$this->addOption('event-json', null, InputOption::VALUE_REQUIRED, 'Event JSON payload');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to notify.', $appId));
			return 1;
		}

		$eventJson = $input->getOption('event-json');
		if ($eventJson === null) {
			$output->writeln('Event JSON payload is required');
			return 1;
		}

		$eventJsonData = json_decode($eventJson, true);
		if ($eventJsonData === null) {
			$output->writeln('Invalid JSON payload');
			return 1;
		}

		$eventListeners = $this->exAppEventsListenerService->getEventsListeners();
		$eventListeners = array_filter($eventListeners, function ($eventListener) use ($eventJson) {
			return $eventListener->getEventType() === $eventJson['event_type'] && in_array($eventJson['event_subtype'], $eventListener->getEventSubtypes());
		});

		if (empty($eventListeners)) {
			$output->writeln(sprintf('No ExApp %s registered listeners found for event: %s', $appId, $eventJson['event_type']));
			return 0;
		}

		$route = $input->getArgument('route');
		$userId = $input->getOption('user-id');
		$response = $this->service->requestToExApp($exApp, $route, $userId, params: $eventJsonData);
		if (is_array($response) && isset($response['error'])) {
			$output->writeln(sprintf('Failed to notify ExApp %s: %s', $appId, $response['error']));
			return 1;
		}

		if ($response->getStatusCode() !== 200) {
			$output->writeln(sprintf('Failed to notify ExApp %s: %s', $appId, $response->getBody()));
			return 1;
		}

		$output->writeln(sprintf('ExApp %s notified about event: %s', $appId, $eventJson));

		return 0;
	}
}
