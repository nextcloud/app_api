<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Command\Daemon;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\DaemonConfigService;

class UnregisterDaemon extends Command {
	private DaemonConfigService $daemonConfigService;

	public function __construct(DaemonConfigService $daemonConfigService) {
		parent::__construct();

		$this->daemonConfigService = $daemonConfigService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:daemon:unregister');
		$this->setDescription('Unregister daemon');

		$this->addArgument('daemon-config-id', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemonConfigId = (int) $input->getArgument('daemon-config-id');

		$daemonConfig = $this->daemonConfigService->getDaemonConfig($daemonConfigId);
		if ($daemonConfig === null) {
			$output->writeln('Daemon config not found.');
			return Command::FAILURE;
		}

		if ($this->daemonConfigService->unregisterDaemonConfig($daemonConfig) === null) {
			$output->writeln('Failed to unregister daemon config.');
			return Command::FAILURE;
		}

		$output->writeln('Daemon config unregistered.');
		return Command::SUCCESS;
	}
}
