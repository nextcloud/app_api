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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListDaemons extends Command {

	public function __construct(
		private readonly DaemonConfigService $daemonConfigService,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:list');
		$this->setDescription('List registered daemons');
		$this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output format (json, table)', 'table');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemonConfigs = $this->daemonConfigService->getRegisteredDaemonConfigs();
		if (count($daemonConfigs) === 0) {
			$output->writeln('No registered daemon configs.');
			return 0;
		}

		$defaultDaemonName = $this->appConfig->getValueString(Application::APP_ID, 'default_daemon_config', lazy: true);

		$outputFormat = $input->getOption('output');
		if (!in_array($outputFormat, ['json', 'table'], true)) {
			$output->writeln(sprintf('<error>Invalid output format "%s". Valid options are: json, table</error>', $outputFormat));
			return 1;
		}

		$output->writeln('Registered ExApp daemon configs:');

		if ($outputFormat === 'json') {
			$allDaemonInfo = [];
			foreach ($daemonConfigs as $daemon) {
				$deployConfig = $daemon->getDeployConfig();

				if (isset($deployConfig['haproxy_password'])) {
					$deployConfig['haproxy_password'] = '***';
				}

				$allDaemonInfo[] = [
					'name' => $daemon->getName(),
					'display_name' => $daemon->getDisplayName(),
					'deploy_id' => $daemon->getAcceptsDeployId(),
					'protocol' => $daemon->getProtocol(),
					'host' => $daemon->getHost(),
					'deploy_config' => $deployConfig,
				];

			}
			$output->writeln(json_encode($allDaemonInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			$table = new Table($output);
			$headers = ['Def', 'Name', 'Display name', 'Deploy ID', 'Protocol', 'Host', 'NC Url', 'Is HaRP', 'HaRP FRP Address', 'HaRP Docker Socket Port'];
			$table->setHeaders($headers);

			$rows = [];
			foreach ($daemonConfigs as $daemon) {
				$deployConfig = $daemon->getDeployConfig();
				$rows[] = [
					$daemon->getName() === $defaultDaemonName ? '*' : '',
					$daemon->getName(),
					$daemon->getDisplayName(),
					$daemon->getAcceptsDeployId(),
					$daemon->getProtocol(),
					$daemon->getHost(),
					$deployConfig['nextcloud_url'],
					isset($deployConfig['harp']) ? 'yes' : 'no',
					$deployConfig['harp']['frp_address'] ?? '(none)',
					$deployConfig['harp']['docker_socket_port'] ?? '(none)',
				];
			}

			$table->setRows($rows);
			$table->render();
		}

		return 0;
	}
}
