<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\Daemon;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\DaemonConfigService;

use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListDaemons extends Command {

	public function __construct(
		private readonly DaemonConfigService $daemonConfigService,
		private readonly IAppConfig          $appConfig
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:list');
		$this->setDescription('List registered daemons');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemonConfigs = $this->daemonConfigService->getRegisteredDaemonConfigs();
		if (count($daemonConfigs) === 0) {
			$output->writeln('No registered daemon configs.');
			return 0;
		}

		$defaultDaemonName = $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', lazy: true);

		$output->writeln('Registered ExApp daemon configs:');
		$table = new Table($output);
		$table->setHeaders(['Def', 'Name', 'Display name', 'Deploy ID', 'Protocol', 'Host', 'NC Url', 'Is HaRP', 'HaRP FRP Address', 'HaRP Docker Socket Port']);
		$rows = [];

		foreach ($daemonConfigs as $daemon) {
			$rows[] = [
				$daemon->getName() === $defaultDaemonName ? '*' : '',
				$daemon->getName(), $daemon->getDisplayName(),
				$daemon->getAcceptsDeployId(),
				$daemon->getProtocol(),
				$daemon->getHost(),
				$daemon->getDeployConfig()['nextcloud_url'],
				isset($daemon->getDeployConfig()['harp']) ? 'yes' : 'no',
				$daemon->getDeployConfig()['harp']['frp_address'] ?? '(none)',
				$daemon->getDeployConfig()['harp']['docker_socket_port'] ?? '(none)',
			];
		}

		$table->setRows($rows);
		$table->render();

		return 0;
	}
}
