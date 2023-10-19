<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\Daemon;

use OCA\AppAPI\Service\DaemonConfigService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnregisterDaemon extends Command {
	private DaemonConfigService $daemonConfigService;

	public function __construct(DaemonConfigService $daemonConfigService) {
		parent::__construct();

		$this->daemonConfigService = $daemonConfigService;
	}

	protected function configure() {
		$this->setName('app_api:daemon:unregister');
		$this->setDescription('Unregister daemon');

		$this->addArgument('daemon-config-name', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemonConfigName = $input->getArgument('daemon-config-name');

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$output->writeln('Daemon config not found.');
			return 1;
		}

		if ($this->daemonConfigService->unregisterDaemonConfig($daemonConfig) === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			return 1;
		}

		if ($this->daemonConfigService->unregisterDaemonConfig($daemonConfig) === null) {
			$output->writeln(sprintf('Failed to unregister daemon config %s.', $daemonConfigName));
			return 1;
		}

		$output->writeln('Daemon config unregistered.');
		return 0;
	}
}
