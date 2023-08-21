<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\DeployActions\DockerActions;
use OCA\AppEcosystemV2\DeployActions\DockerAIOActions;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCA\AppEcosystemV2\Service\DaemonConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Unregister extends Command {
	private AppEcosystemV2Service $service;
	private DockerActions $dockerActions;
	private DaemonConfigService $daemonConfigService;
	private DockerAIOActions $dockerAIOActions;

	public function __construct(
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		DockerActions $dockerActions,
		DockerAIOActions $dockerAIOActions,
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
		$this->dockerAIOActions = $dockerAIOActions;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:unregister');
		$this->setDescription('Unregister external app');

		$this->addArgument('appid', InputArgument::REQUIRED);

		$this->addOption('silent', null, InputOption::VALUE_NONE, 'Unregister only from Nextcloud. Do not send request to external app.');
		$this->addOption('rm-container', null, InputOption::VALUE_NONE, 'Remove ExApp container');
		$this->addOption('rm-data', null, InputOption::VALUE_NONE, 'Remove ExApp data (volume)');

		$this->addUsage('test_app');
		$this->addUsage('test_app --silent');
		$this->addUsage('test_app --rm-container');
		$this->addUsage('test_app --rm-container --rm-data');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to unregister.', $appId));
			return 1;
		}

		$silent = $input->getOption('silent');

		if (!$silent) {
			if ($this->service->disableExApp($exApp)) {
				$output->writeln(sprintf('ExApp %s successfully disabled.', $appId));
			} else {
				$output->writeln(sprintf('ExApp %s not disabled. Failed to disable.', $appId));
				return 1;
			}
		}

		$exApp = $this->service->unregisterExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('Failed to unregister ExApp %s.', $appId));
			return 1;
		}

		$rmContainer = $input->getOption('rm-container');
		if ($rmContainer) {
			$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
			if ($daemonConfig === null) {
				$output->writeln(sprintf('Failed to get ExApp %s DaemonConfig by name %s', $appId, $exApp->getDaemonConfigName()));
				return 1;
			}
			if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
				$deployActions = $this->dockerActions;
			} elseif ($daemonConfig->getAcceptsDeployId() === $this->dockerAIOActions->getAcceptsDeployId()) {
				$deployActions = $this->dockerAIOActions;
			} else {
				return 1;
			}
			$deployActions->initGuzzleClient($daemonConfig);
			[$stopResult, $removeResult] = $deployActions->removePrevExAppContainer($deployActions->buildDockerUrl($daemonConfig), $appId);
			if (isset($stopResult['error']) || isset($removeResult['error'])) {
				$output->writeln(sprintf('Failed to remove ExApp %s container', $appId));
			} else {
				$rmData = $input->getOption('rm-data');
				if ($rmData) {
					$removeVolumeResult = $deployActions->removeVolume($deployActions->buildDockerUrl($daemonConfig), $appId . '_data');
					if (isset($removeVolumeResult['error'])) {
						$output->writeln(sprintf('Failed to remove ExApp %s volume %s', $appId, $appId . '_data'));
					}
				}
				$output->writeln(sprintf('ExApp %s container successfully removed', $appId));
			}
		}

		$output->writeln(sprintf('ExApp %s successfully unregistered.', $appId));
		return 0;
	}
}
