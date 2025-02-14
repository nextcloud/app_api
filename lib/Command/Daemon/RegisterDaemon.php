<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\Daemon;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\DaemonConfigService;

use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegisterDaemon extends Command {

	public function __construct(
		private DaemonConfigService $daemonConfigService,
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:daemon:register');
		$this->setDescription('Register daemon config for ExApp deployment');

		// todo: add docs
		$this->addArgument('name', InputArgument::REQUIRED);
		$this->addArgument('display-name', InputArgument::REQUIRED);
		$this->addArgument('accepts-deploy-id', InputArgument::REQUIRED);
		$this->addArgument('protocol', InputArgument::REQUIRED);
		$this->addArgument('host', InputArgument::REQUIRED);
		$this->addArgument('nextcloud_url', InputArgument::REQUIRED);

		// daemon-config settings
		$this->addOption('net', null, InputOption::VALUE_REQUIRED, 'DeployConfig, the name of the docker network to attach App to');
		$this->addOption('haproxy_password', null, InputOption::VALUE_REQUIRED, 'AppAPI Docker Socket Proxy password for HAProxy Basic auth');
		$this->addOption('compute_device', null, InputOption::VALUE_REQUIRED, 'Compute device for GPU support (cpu|cuda|rocm)');
		$this->addOption('set-default', null, InputOption::VALUE_NONE, 'Set DaemonConfig as default');
		$this->addOption('harp', null, InputOption::VALUE_NONE, 'Set daemon to use HaRP for all docker and exapp communication');
		$this->addOption('harp_frp_address', null, InputOption::VALUE_REQUIRED, '[host]:[port] of the HaRP FRP server, default host is same as HaRP host and port is 8782');
		$this->addOption('harp_shared_key', null, InputOption::VALUE_REQUIRED, 'HaRP shared key for secure communication between HaRP and AppAPI');

		$this->addUsage('manual_install "Manual Install" "manual-install" "http" null "http://nextcloud.local"');
		$this->addUsage('local_docker "Docker Local" "docker-install" "http" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud');
		$this->addUsage('local_docker "Docker Local" "docker-install" "http" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud --set-default --compute_device=cuda');
		$this->addUsage('docker_install "Docker Socket Proxy" "docker-install" "http" "nextcloud-appapi-dsp:2375" "http://nextcloud.local" --net=nextcloud --set-default --compute_device=cuda');
		$this->addUsage('harp_install "Harp Install" "docker-install" "http" "nextcloud-appapi-harp:8780" "http://nextcloud.local" --harp --harp_frp_address "nextcloud-appapi-harp:8782" --harp_shared_key "some_very_secure_password" --set-default --compute_device=cuda');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('name');
		$displayName = $input->getArgument('display-name');
		$acceptsDeployId = $input->getArgument('accepts-deploy-id');
		$protocol = $input->getArgument('protocol');
		$host = $input->getArgument('host');
		$nextcloudUrl = $input->getArgument('nextcloud_url');
		$isHarp = $input->getOption('harp') !== null;

		if (($protocol !== 'http') && ($protocol !== 'https')) {
			$output->writeln('Value error: The protocol must be `http` or `https`.');
			return 1;
		}
		if ($acceptsDeployId === 'manual-install' && $protocol !== 'http') {
			$output->writeln('Value error: Manual-install daemon supports only `http` protocol.');
			return 1;
		}
		// todo:
		if ($acceptsDeployId === 'manual-install' && $isHarp) {
			$output->writeln('Value error: Manual-install daemon does not support HaRP.');
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
			'harp' => $isHarp,
			'harp_frp_address' => $input->getOption('harp_frp_address') ?? '',
		];

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
			$this->config->setAppValue(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName());
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
