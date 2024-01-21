<?php

declare(strict_types=1);

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

		$this->addArgument('name', InputArgument::REQUIRED);
		$this->addArgument('display-name', InputArgument::REQUIRED);
		$this->addArgument('accepts-deploy-id', InputArgument::REQUIRED);
		$this->addArgument('protocol', InputArgument::REQUIRED);
		$this->addArgument('host', InputArgument::REQUIRED);
		$this->addArgument('nextcloud_url', InputArgument::REQUIRED);

		// daemon-config settings
		$this->addOption('net', null, InputOption::VALUE_REQUIRED, 'DeployConfig, the name of the docker network to attach App to');
		$this->addOption('haproxy_password', null, InputOption::VALUE_REQUIRED, 'AppAPI Docker Socket Proxy password for HAProxy Basic auth');

		$this->addOption('gpu', null, InputOption::VALUE_NONE, 'Enable support of GPUs for containers');

		$this->addOption('set-default', null, InputOption::VALUE_NONE, 'Set DaemonConfig as default');

		$this->addUsage('local_docker "Docker local" "docker-install" "unix-socket" "/var/run/docker.sock" "http://nextcloud.local" --net=nextcloud');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('name');
		$displayName = $input->getArgument('display-name');
		$acceptsDeployId = $input->getArgument('accepts-deploy-id');
		$protocol = $input->getArgument('protocol');
		$host = $input->getArgument('host');
		$nextcloudUrl = $input->getArgument('nextcloud_url');

		$deployConfig = [
			'net' => $input->getOption('net') ?? 'host',
			'nextcloud_url' => $nextcloudUrl,
			'haproxy_password' => $input->getOption('haproxy_password'),
			'gpu' => $input->getOption('gpu') ?? false,
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
}
