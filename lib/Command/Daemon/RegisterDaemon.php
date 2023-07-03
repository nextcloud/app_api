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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\DaemonConfigService;

class RegisterDaemon extends Command {
	private DaemonConfigService $daemonConfigService;

	public function __construct(DaemonConfigService $daemonConfigService) {
		parent::__construct();

		$this->daemonConfigService = $daemonConfigService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:daemon:register');
		$this->setDescription('Register daemon config for ExApp deployment');

		$this->addArgument('accepts-deploy-id', InputArgument::REQUIRED);
		$this->addArgument('display-name', InputArgument::REQUIRED);
		$this->addArgument('protocol', InputArgument::REQUIRED);
		$this->addArgument('host', InputArgument::REQUIRED);
		$this->addArgument('port', InputArgument::OPTIONAL, 'Port of the daemon, only required for network protocol', 0);

		// daemon-config settings
		$this->addOption('net', null, InputOption::VALUE_REQUIRED, 'DeployConfig, docker network name');
		$this->addOption('expose', null, InputOption::VALUE_OPTIONAL, 'DeployConfig, expose container port [local, global, null]');
		$this->addOption('host', null, InputOption::VALUE_REQUIRED, 'DeployConfig, docker daemon host (e.g. host.docker.internal)');

		$this->addUsage('"docker-install" "Docker local" "unix-socket" "var/run/docker.sock" 0 --net "nextcloud" --expose local --host "host.docker.internal"');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$acceptsDeployId = $input->getArgument('accepts-deploy-id');
		$displayName = $input->getArgument('display-name');
		$protocol = $input->getArgument('protocol');
		$host = $input->getArgument('host');
		$port = $input->getArgument('port');

		$deployConfig = [
			'net' => $input->getOption('net'),
			'expose' => $input->getOption('expose'), // expose: local, host, null
			'host' => $input->getOption('host'),
		];

		$daemonConfig = $this->daemonConfigService->registerDaemonConfig([
			'accepts_deploy_id' => $acceptsDeployId,
			'display_name' => $displayName,
			'protocol' => $protocol,
			'host' => $host,
			'port' => $port,
			'deploy_config' => $deployConfig,
		]);

		if ($daemonConfig === null) {
			$output->writeln('Failed to register daemon.');
			return Command::FAILURE;
		}

		$output->writeln(sprintf('Daemon successfully registered. Daemon config ID: %s', $daemonConfig->getId()));
		return Command::SUCCESS;
	}
}
