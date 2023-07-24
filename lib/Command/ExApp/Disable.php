<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Disable extends Command {
	private AppEcosystemV2Service $service;

	public function __construct(AppEcosystemV2Service $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:disable');
		$this->setDescription('Disable registered external app');
		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);

		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to disable.', $appId));
			return 1;
		}
		if (!$exApp->getEnabled()) {
			$output->writeln(sprintf('ExApp %s already disabled.', $appId));
			return 0;
		}

		if ($this->service->disableExApp($exApp)) {
			$output->writeln(sprintf('ExApp %s successfully disabled.', $appId));
			return 0;
		}

		$output->writeln(sprintf('Failed to disable ExApp %s.', $appId));
		return 1;
	}
}
