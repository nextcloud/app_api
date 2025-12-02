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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegisterDaemon extends Command {

	public function __construct(
		private DaemonConfigService $daemonConfigService,
		private IAppConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:register');
		$this->setDescription('Register daemon config for ExApp deployment');

		$this->addArgument('name', InputArgument::REQUIRED, 'Unique deploy daemon name');
		$this->addArgument('display-name', InputArgument::REQUIRED);
		$this->addArgument('accepts-deploy-id', InputArgument::REQUIRED, 'The deployment method that the daemon accepts. Can be "manual-install" or "docker-install". "docker-install" is for Docker Socket Proxy and HaRP.');
		$this->addArgument('protocol', InputArgument::REQUIRED, 'The protocol used to connect to the daemon. Can be "http" or "https".');
		$this->addArgument('host', InputArgument::REQUIRED, 'The hostname (and port) or path at which the docker socket proxy or harp or the manual-install app is/would be available. This need not be a public host, just a host accessible by the Nextcloud server. It can also be a path to the docker socket. (e.g. appapi-harp:8780, /var/run/docker.sock)');
		$this->addArgument('nextcloud_url', InputArgument::REQUIRED);

		// daemon-config settings
		$this->addOption('net', null, InputOption::VALUE_REQUIRED, 'The name of the docker network the ex-apps installed by this daemon should use. Default is "host".');
		$this->addOption('haproxy_password', null, InputOption::VALUE_REQUIRED, 'AppAPI Docker Socket Proxy password for HAProxy Basic auth. Only for docker socket proxy daemon.');
		$this->addOption('compute_device', null, InputOption::VALUE_REQUIRED, 'Compute device for GPU support (cpu|cuda|rocm)');
		$this->addOption('set-default', null, InputOption::VALUE_NONE, 'Set DaemonConfig as default');
		$this->addOption('harp', null, InputOption::VALUE_NONE, 'Set daemon to use HaRP for all docker and exapp communication');
		$this->addOption('harp_frp_address', null, InputOption::VALUE_REQUIRED, '[host]:[port] of the HaRP FRP server, default host is same as HaRP host and port is 8782');
		$this->addOption('harp_shared_key', null, InputOption::VALUE_REQUIRED, 'HaRP shared key for secure communication between HaRP and AppAPI');
		$this->addOption('harp_docker_socket_port', null, InputOption::VALUE_REQUIRED, '\'remotePort\' of the FRP client of the remote docker socket proxy. There is one included in the harp container so this can be skipped for default setups.', '24000');
		$this->addOption('harp_exapp_direct', null, InputOption::VALUE_NONE, 'Flag for the advanced setups only. Disables the FRP tunnel between ExApps and HaRP.');

		$this->addUsage('harp_proxy_docker "Harp Proxy (Docker)" "docker-install" "http" "appapi-harp:8780" "http://nextcloud.local" --net nextcloud --harp --harp_frp_address "appapi-harp:8782" --harp_shared_key "some_very_secure_password" --set-default --compute_device=cuda');
		$this->addUsage('harp_proxy_host "Harp Proxy (Host)" "docker-install" "http" "localhost:8780" "http://nextcloud.local" --harp --harp_frp_address "localhost:8782" --harp_shared_key "some_very_secure_password" --set-default --compute_device=cuda');
		$this->addUsage('manual_install_harp "Harp Manual Install" "manual-install" "http" "appapi-harp:8780" "http://nextcloud.local" --net nextcloud --harp --harp_frp_address "appapi-harp:8782" --harp_shared_key "some_very_secure_password"');
		$this->addUsage('docker_install "Docker Socket Proxy" "docker-install" "http" "nextcloud-appapi-dsp:2375" "http://nextcloud.local" --net=nextcloud --set-default --compute_device=cuda');
		$this->addUsage('manual_install "Manual Install" "manual-install" "http" null "http://nextcloud.local"');
		$this->addUsage('local_docker "Docker Local" "docker-install" "http" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud');
		$this->addUsage('local_docker "Docker Local" "docker-install" "http" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud --set-default --compute_device=cuda');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('name');
		$displayName = $input->getArgument('display-name');
		$acceptsDeployId = $input->getArgument('accepts-deploy-id');
		$protocol = $input->getArgument('protocol');
		$host = $input->getArgument('host');
		$nextcloudUrl = $input->getArgument('nextcloud_url');
		$isHarp = $input->getOption('harp');

		if (($protocol !== 'http') && ($protocol !== 'https')) {
			$output->writeln('Value error: The protocol must be `http` or `https`.');
			return 1;
		}
		if ($isHarp && !$input->getOption('harp_shared_key')) {
			$output->writeln('Value error: HaRP enabled daemon requires `harp_shared_key` option.');
			return 1;
		}
		if ($isHarp && !$input->getOption('harp_frp_address')) {
			$output->writeln('Value error: HaRP enabled daemon requires `harp_frp_address` option.');
			return 1;
		}

		if ($this->daemonConfigService->getDaemonConfigByName($name) !== null) {
			$output->writeln(sprintf('Skip registration, as daemon config `%s` already registered.', $name));
			return 0;
		}

		$secret = $isHarp
			? $input->getOption('harp_shared_key')
			: $input->getOption('haproxy_password') ?? '';

		$deployConfig = [
			'net' => $input->getOption('net') ?? 'host',
			'nextcloud_url' => $nextcloudUrl,
			'haproxy_password' => $secret,
			'computeDevice' => $this->buildComputeDevice($input->getOption('compute_device') ?? 'cpu'),
			'harp' => null,
		];
		if ($isHarp) {
			$deployConfig['harp'] = [
				'frp_address' => $input->getOption('harp_frp_address') ?? '',
				'docker_socket_port' => $input->getOption('harp_docker_socket_port'),
				'exapp_direct' => (bool)$input->getOption('harp_exapp_direct'),
			];
		}

		$daemonConfig = $this->daemonConfigService->registerDaemonConfig([
			'name' => $name,
			'display_name' => $displayName,
			'accepts_deploy_id' => $acceptsDeployId,
			'protocol' => $protocol,
			'host' => $host,
			'deploy_config' => $deployConfig,
		]);

		if ($daemonConfig === null) {
			$output->writeln('Failed to register daemon.');
			return 1;
		}

		if ($input->getOption('set-default')) {
			$this->config->setValueString(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName(), lazy: true);
		}

		$output->writeln('Daemon successfully registered.');
		return 0;
	}

	private function buildComputeDevice(string $computeDevice): array {
		switch ($computeDevice) {
			case 'cpu':
				return [
					'id' => 'cpu',
					'label' => 'CPU',
				];
			case 'cuda':
				return [
					'id' => 'cuda',
					'label' => 'CUDA (NVIDIA)',
				];
			case 'rocm':
				return [
					'id' => 'rocm',
					'label' => 'ROCm (AMD)',
				];
			default:
				throw new \InvalidArgumentException('Invalid compute device value.');
		}
	}
}
