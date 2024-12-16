<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\Daemon;

use OCA\AppAPI\Service\DaemonConfigService;

use OCA\AppAPI\Service\ExAppService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnregisterDaemon extends Command {

	public function __construct(
		private readonly DaemonConfigService $daemonConfigService,
		private readonly ExAppService		 $exAppService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:unregister');
		$this->setDescription('Unregister daemon');

		$this->addArgument('daemon-config-name', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemonConfigName = $input->getArgument('daemon-config-name');

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			return 1;
		}

		$countExApps = count($this->exAppService->getExAppsByDaemonName($daemonConfigName));
		if ($countExApps > 0) {
			$output->writeln(sprintf('Error: %s daemon contains %d ExApps, please remove them first to proceed.', $daemonConfigName, $countExApps));
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
