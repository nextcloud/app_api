<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Unregister extends Command {
	private AppEcosystemV2Service $service;

	public function __construct(AppEcosystemV2Service $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:unregister');
		$this->setDescription('Unregister external app');

		$this->addArgument('appid', InputArgument::REQUIRED);

		$this->addOption('silent', null, InputOption::VALUE_NONE, 'Unregister only from Nextcloud. Do not send request to external app.');

		$this->addUsage('test_app');
		$this->addUsage('test_app --silent');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to unregister.', $appId));
			return 1;
		}

		$silent = $input->getOption('silent');

		if (!$silent) {
			if ($this->service->disableExApp($exApp)) {
				$output->writeln(sprintf('ExApp %s successfully disabled.', $appId));
			} else {
				$output->writeln(sprintf('ExApp %s not disabled. Failed to disable.', $appId));
				return 1;
			}
		}

		$exApp = $this->service->unregisterExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('Failed to unregister ExApp %s.', $appId));
			return 1;
		}

		$output->writeln(sprintf('ExApp %s successfully unregistered.', $appId));
		return 0;
	}
}
