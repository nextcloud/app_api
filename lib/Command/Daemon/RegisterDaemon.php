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
		$this->addArgument('host', InputArgument::REQUIRED, 'The hostname (and port) or path at which the Docker socket proxy or HaRP or the manual-install app is/would be available. This does not need to be a public host, just a host accessible by the Nextcloud server. It can also be a path to the Docker socket. (e.g. appapi-harp:8780, /var/run/docker.sock)');
		$this->addArgument('nextcloud_url', InputArgument::REQUIRED);

		// daemon-config settings
		$this->addOption('net', null, InputOption::VALUE_REQUIRED, 'The name of the Docker network the ex-apps installed by this daemon should use. Default is "host".');
		$this->addOption('haproxy_password', null, InputOption::VALUE_REQUIRED, 'AppAPI Docker Socket Proxy password for HAProxy Basic auth. Only for Docker Socket Proxy daemons.');
		$this->addOption('compute_device', null, InputOption::VALUE_REQUIRED, 'Computation device for GPU support (cpu|cuda|rocm)');
		$this->addOption('set-default', null, InputOption::VALUE_NONE, 'Set DaemonConfig as default');
		$this->addOption('harp', null, InputOption::VALUE_NONE, 'Set the daemon to use HaRP for all Docker and ExApp communication');
		$this->addOption('harp_frp_address', null, InputOption::VALUE_REQUIRED, '[host]:[port] of the HaRP FRP server, the default host is same as the HaRP host, port is 8782');
		$this->addOption('harp_shared_key', null, InputOption::VALUE_REQUIRED, 'HaRP shared key for secure communication between HaRP and AppAPI');
		$this->addOption('harp_docker_socket_port', null, InputOption::VALUE_REQUIRED, '\'remotePort\' of the FRP client of the remote Docker socket proxy. There is one included in the harp container so this can be skipped for default setups.', '24000');
		$this->addOption('harp_exapp_direct', null, InputOption::VALUE_NONE, 'Flag for the advanced setups only. Disables the FRP tunnel between ExApps and HaRP.');

		// Kubernetes options
		$this->addOption('k8s', null, InputOption::VALUE_NONE, 'Flag to indicate Kubernetes daemon (uses kubernetes-install deploy ID). Requires --harp flag.');
		$this->addOption('k8s_expose_type', null, InputOption::VALUE_REQUIRED, 'Kubernetes Service type: nodeport|clusterip|loadbalancer|manual (default: clusterip)', 'clusterip');
		$this->addOption('k8s_node_port', null, InputOption::VALUE_REQUIRED, 'Optional NodePort (30000-32767) for nodeport expose type');
		$this->addOption('k8s_upstream_host', null, InputOption::VALUE_REQUIRED, 'Override upstream host for HaRP to reach ExApps. Required for manual expose type.');
		$this->addOption('k8s_external_traffic_policy', null, InputOption::VALUE_REQUIRED, 'Cluster|Local for NodePort/LoadBalancer Service types');
		$this->addOption('k8s_load_balancer_ip', null, InputOption::VALUE_REQUIRED, 'Optional LoadBalancer IP for loadbalancer expose type');
		$this->addOption('k8s_node_address_type', null, InputOption::VALUE_REQUIRED, 'InternalIP|ExternalIP for auto node selection (default: InternalIP)', 'InternalIP');

		$this->addUsage('harp_proxy_docker "Harp Proxy (Docker)" "docker-install" "http" "appapi-harp:8780" "http://nextcloud.local" --net nextcloud --harp --harp_frp_address "appapi-harp:8782" --harp_shared_key "some_very_secure_password" --set-default --compute_device=cuda');
		$this->addUsage('harp_proxy_host "Harp Proxy (Host)" "docker-install" "http" "localhost:8780" "http://nextcloud.local" --harp --harp_frp_address "localhost:8782" --harp_shared_key "some_very_secure_password" --set-default --compute_device=cuda');
		$this->addUsage('manual_install_harp "Harp Manual Install" "manual-install" "http" "appapi-harp:8780" "http://nextcloud.local" --net nextcloud --harp --harp_frp_address "appapi-harp:8782" --harp_shared_key "some_very_secure_password"');
		$this->addUsage('docker_install "Docker Socket Proxy" "docker-install" "http" "nextcloud-appapi-dsp:2375" "http://nextcloud.local" --net=nextcloud --set-default --compute_device=cuda');
		$this->addUsage('manual_install "Manual Install" "manual-install" "http" null "http://nextcloud.local"');
		$this->addUsage('local_docker "Docker Local" "docker-install" "http" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud');
		$this->addUsage('local_docker "Docker Local" "docker-install" "http" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud --set-default --compute_device=cuda');

		// Kubernetes usage examples
		$this->addUsage('k8s_daemon "Kubernetes HaRP" "kubernetes-install" "http" "harp.nextcloud.svc:8780" "http://nextcloud.local" --harp --harp_shared_key "secret" --harp_frp_address "harp.nextcloud.svc:8782" --k8s');
		$this->addUsage('k8s_daemon_nodeport "K8s NodePort" "kubernetes-install" "http" "harp.example.com:8780" "http://nextcloud.local" --harp --harp_shared_key "secret" --harp_frp_address "harp.example.com:8782" --k8s --k8s_expose_type=nodeport --k8s_upstream_host="k8s-node.example.com"');
		$this->addUsage('k8s_daemon_lb "K8s LoadBalancer" "kubernetes-install" "http" "harp.example.com:8780" "http://nextcloud.local" --harp --harp_shared_key "secret" --harp_frp_address "harp.example.com:8782" --k8s --k8s_expose_type=loadbalancer');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('name');
		$displayName = $input->getArgument('display-name');
		$acceptsDeployId = $input->getArgument('accepts-deploy-id');
		$protocol = $input->getArgument('protocol');
		$host = $input->getArgument('host');
		$nextcloudUrl = $input->getArgument('nextcloud_url');
		$isHarp = $input->getOption('harp');
		$isK8s = $input->getOption('k8s');

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

		// Kubernetes validation
		if ($isK8s) {
			if (!$isHarp) {
				$output->writeln('Value error: Kubernetes daemon (--k8s) requires --harp flag. K8s always uses HaRP.');
				return 1;
			}
			// Override accepts-deploy-id for K8s
			if ($acceptsDeployId !== 'kubernetes-install') {
				$output->writeln('<comment>Note: --k8s flag detected. Overriding accepts-deploy-id to "kubernetes-install".</comment>');
				$acceptsDeployId = 'kubernetes-install';
			}

			$k8sExposeType = $input->getOption('k8s_expose_type');
			$validExposeTypes = ['nodeport', 'clusterip', 'loadbalancer', 'manual'];
			if (!in_array($k8sExposeType, $validExposeTypes)) {
				$output->writeln(sprintf('Value error: Invalid k8s_expose_type "%s". Must be one of: %s', $k8sExposeType, implode(', ', $validExposeTypes)));
				return 1;
			}

			$k8sNodePort = $input->getOption('k8s_node_port');
			if ($k8sNodePort !== null) {
				$k8sNodePort = (int)$k8sNodePort;
				if ($k8sExposeType !== 'nodeport') {
					$output->writeln('Value error: --k8s_node_port is only valid with --k8s_expose_type=nodeport');
					return 1;
				}
				if ($k8sNodePort < 30000 || $k8sNodePort > 32767) {
					$output->writeln('Value error: --k8s_node_port must be between 30000 and 32767');
					return 1;
				}
			}

			$k8sLoadBalancerIp = $input->getOption('k8s_load_balancer_ip');
			if ($k8sLoadBalancerIp !== null && $k8sExposeType !== 'loadbalancer') {
				$output->writeln('Value error: --k8s_load_balancer_ip is only valid with --k8s_expose_type=loadbalancer');
				return 1;
			}

			$k8sUpstreamHost = $input->getOption('k8s_upstream_host');
			if ($k8sExposeType === 'manual' && $k8sUpstreamHost === null) {
				$output->writeln('Value error: --k8s_upstream_host is required for --k8s_expose_type=manual');
				return 1;
			}

			$k8sExternalTrafficPolicy = $input->getOption('k8s_external_traffic_policy');
			if ($k8sExternalTrafficPolicy !== null) {
				$validPolicies = ['Cluster', 'Local'];
				if (!in_array($k8sExternalTrafficPolicy, $validPolicies)) {
					$output->writeln(sprintf('Value error: Invalid k8s_external_traffic_policy "%s". Must be one of: %s', $k8sExternalTrafficPolicy, implode(', ', $validPolicies)));
					return 1;
				}
			}

			$k8sNodeAddressType = $input->getOption('k8s_node_address_type');
			$validNodeAddressTypes = ['InternalIP', 'ExternalIP'];
			if (!in_array($k8sNodeAddressType, $validNodeAddressTypes)) {
				$output->writeln(sprintf('Value error: Invalid k8s_node_address_type "%s". Must be one of: %s', $k8sNodeAddressType, implode(', ', $validNodeAddressTypes)));
				return 1;
			}
		}

		if ($acceptsDeployId === 'manual-install' && !$isHarp && str_contains($host, ':')) {
			$output->writeln('<comment>Warning: The host contains a port, which will be ignored for manual-install daemons. The ExApp\'s port from --json-info will be used instead.</comment>');
		}

		if ($this->daemonConfigService->getDaemonConfigByName($name) !== null) {
			$output->writeln(sprintf('Registration skipped, as the daemon config `%s` already exists.', $name));
			return 0;
		}

		$secret = $isHarp
			? $input->getOption('harp_shared_key')
			: $input->getOption('haproxy_password') ?? '';

		// For K8s, 'net' is not used (K8s has its own networking), default to 'bridge' to avoid validation issues
		$defaultNet = $isK8s ? 'bridge' : 'host';
		$deployConfig = [
			'net' => $input->getOption('net') ?? $defaultNet,
			'nextcloud_url' => $nextcloudUrl,
			'haproxy_password' => $secret,
			'computeDevice' => $this->buildComputeDevice($input->getOption('compute_device') ?? 'cpu'),
			'harp' => null,
			'kubernetes' => null,
		];
		if ($isHarp) {
			$deployConfig['harp'] = [
				'frp_address' => $input->getOption('harp_frp_address') ?? '',
				'docker_socket_port' => $input->getOption('harp_docker_socket_port'),
				'exapp_direct' => $isK8s ? true : (bool)$input->getOption('harp_exapp_direct'), // K8s always uses direct (Service-based) routing
			];
		}
		if ($isK8s) {
			$k8sNodePort = $input->getOption('k8s_node_port');
			$deployConfig['kubernetes'] = [
				'expose_type' => $input->getOption('k8s_expose_type') ?? 'clusterip',
				'node_port' => $k8sNodePort !== null ? (int)$k8sNodePort : null,
				'upstream_host' => $input->getOption('k8s_upstream_host'),
				'external_traffic_policy' => $input->getOption('k8s_external_traffic_policy'),
				'load_balancer_ip' => $input->getOption('k8s_load_balancer_ip'),
				'node_address_type' => $input->getOption('k8s_node_address_type') ?? 'InternalIP',
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
			$output->writeln('Failed to register the daemon config.');
			return 1;
		}

		if ($input->getOption('set-default')) {
			$this->config->setValueString(Application::APP_ID, 'default_daemon_config', $daemonConfig->getName(), lazy: true);
		}

		$output->writeln('Daemon config successfully registered.');
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
