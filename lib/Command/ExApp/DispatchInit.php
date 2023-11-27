<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\Service\AppAPIService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DispatchInit extends Command {

	public function __construct(
		private AppAPIService        $service,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setHidden(true);
		$this->setName('app_api:app:dispatch_init');
		$this->setDescription('Internal command to dispatch init command');

		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to dispatch init.', $appId));
			return 1;
		}
		$this->service->dispatchExAppInitInternal($exApp);
		return 0;
	}
}
