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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveRegistry extends Command {

	public function __construct(
		private readonly DaemonConfigService $daemonConfigService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:registry:remove');
		$this->setDescription('Remove Deploy daemon Docker registry mapping');
		$this->addArgument('name', InputArgument::REQUIRED, 'Deploy daemon name');
		$this->addOption('registry-from', null, InputOption::VALUE_REQUIRED, 'Deploy daemon registry from URL');
		$this->addOption('registry-to', null, InputOption::VALUE_REQUIRED, 'Deploy daemon registry to URL');
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

		$registryFrom = $input->getOption('registry-from');
		$registryTo = $input->getOption('registry-to');
		if (!$registryFrom || !$registryTo) {
			$output->writeln('Registry URL pair (from -> to) is required.');
			return 1;
		}

		$daemonConfig = $this->daemonConfigService->removeDockerRegistry($daemonConfig, [
			'from' => $registryFrom,
			'to' => $registryTo,
		]);
		if (is_array($daemonConfig) && isset($daemonConfig['error'])) {
			$output->writeln(sprintf('Error adding Docker registry: %s', $daemonConfig['error']));
			return 1;
		}
		if ($daemonConfig === null) {
			$output->writeln('Failed to remove registry mapping.');
			return 1;
		}

		$output->writeln(sprintf('Removed registry mapping from "%s" to "%s" for daemon "%s".', $registryFrom, $registryTo, $name));
		return 0;
	}
}
