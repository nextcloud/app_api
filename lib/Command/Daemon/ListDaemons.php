<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\Daemon;

use OCA\AppAPI\Service\DaemonConfigService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListDaemons extends Command {
	private DaemonConfigService $daemonConfigService;

	public function __construct(DaemonConfigService $daemonConfigService) {
		parent::__construct();

		$this->daemonConfigService = $daemonConfigService;
	}

	protected function configure() {
		$this->setName('app_api:daemon:list');
		$this->setDescription('List registered daemons');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemonConfigs = $this->daemonConfigService->getRegisteredDaemonConfigs();
		if ($daemonConfigs === null) {
			$output->writeln('<error>Failed to get list of daemons.</error>');
			return 1;
		}

		if (count($daemonConfigs) === 0) {
			$output->writeln('No registered daemon configs.');
			return 0;
		}

		$output->writeln('Registered ExApp daemon configs:');
		foreach ($daemonConfigs as $daemon) {
			$output->writeln(sprintf('%s. %s - %s [%s]: %s://%s', $daemon->getId(), $daemon->getName(), $daemon->getDisplayName(), $daemon->getAcceptsDeployId(), $daemon->getProtocol(), $daemon->getHost()));
		}

		return 0;
	}
}
