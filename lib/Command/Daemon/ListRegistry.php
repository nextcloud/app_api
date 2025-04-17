<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\Daemon;

use OCA\AppAPI\Service\DaemonConfigService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListRegistry extends Command {
	public function __construct(
		private readonly DaemonConfigService $daemonConfigService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:registry:list');
		$this->setDescription('List configured Deploy daemon Docker registry mappings');
		$this->addArgument('name', InputArgument::REQUIRED, 'Deploy daemon name');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('name');
		if (!$name) {
			$output->writeln('Daemon name is required.');
			return 1;
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($name);
		if ($daemonConfig === null) {
			$output->writeln('Daemon config not found.');
			return 1;
		}

		if (!isset($daemonConfig->getDeployConfig()['registries']) || count($daemonConfig->getDeployConfig()['registries']) === 0) {
			$output->writeln(sprintf('No registries configured for daemon "%s".', $name));
			return 0;
		}

		$registries = $daemonConfig->getDeployConfig()['registries'];
		$output->writeln(sprintf('Configured registries for daemon "%s":', $name));
		foreach ($registries as $registry) {
			$output->writeln(sprintf(' - %s -> %s', $registry['from'], $registry['to']));
		}

		return 0;
	}
}
