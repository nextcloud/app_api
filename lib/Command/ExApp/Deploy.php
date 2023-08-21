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

class Deploy extends Command {
	private AppEcosystemV2Service $service;
	private DaemonConfigService $daemonConfigService;
	private DockerActions $dockerActions;
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
		$this->setName('app_ecosystem_v2:app:deploy');
		$this->setDescription('Deploy ExApp on configured daemon');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-name', InputArgument::REQUIRED);

		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, '[required] Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('env', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Docker container environment variables', []);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$pathToInfoXml = $input->getOption('info-xml');
		if ($pathToInfoXml === null) {
			$output->writeln(sprintf('No info.xml specified for %s', $appId));
			return 2;
		}

		$infoXml = simplexml_load_string(file_get_contents($pathToInfoXml));
		if ($infoXml === false) {
			$output->writeln(sprintf('Failed to load info.xml from %s', $pathToInfoXml));
			return 2;
		}
		if ($appId !== (string) $infoXml->id) {
			$output->writeln(sprintf('ExApp appid %s does not match appid in info.xml (%s)', $appId, $infoXml->id));
			return 2;
		}

		$exApp = $this->service->getExApp($appId);
		if ($exApp !== null) {
			$output->writeln(sprintf('ExApp %s already registered.', $appId));
			return 2;
		}

		$daemonConfigName = $input->getArgument('daemon-config-name');
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			return 2;
		}

		if ($daemonConfig->getAcceptsDeployId() === 'manual-install') {
			$output->writeln('For "manual-install" deployId update is done manually. Only registration required.');
			return 1;
		}

		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$deployActions = $this->dockerActions;
		} elseif ($daemonConfig->getAcceptsDeployId() === $this->dockerAIOActions->getAcceptsDeployId()) {
			$deployActions = $this->dockerAIOActions;
		} else {
			$output->writeln(sprintf('Unsupported DaemonConfig type (accepts-deploy-id): %s', $daemonConfig->getAcceptsDeployId()));
			return 1;
		}
		$deployParams = $deployActions->buildDeployParams($daemonConfig, $infoXml, [
			'env_options' => $input->getOption('env'),
		]);
		[$pullResult, $createResult, $startResult] = $deployActions->deployExApp($daemonConfig, $deployParams);

		if (isset($pullResult['error'])) {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $pullResult['error']));
			return 1;
		}

		if (!isset($startResult['error']) && isset($createResult['Id'])) {
			if (!$deployActions->healthcheckContainer($createResult['Id'], $daemonConfig)) {
				$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, 'Container healthcheck failed.'));
				return 1;
			}

			$exAppUrlParams = [
				'protocol' => (string) ($infoXml->xpath('ex-app/protocol')[0] ?? 'http'),
				'host' => $deployActions->resolveDeployExAppHost($appId, $daemonConfig),
				'port' => explode('=', $deployParams['container_params']['env'][7])[1],
			];

			if (!$this->service->heartbeatExApp($exAppUrlParams)) {
				$output->writeln(sprintf('ExApp %s heartbeat check failed. Make sure container started and initialized correctly.', $appId));
				return 0;
			}

			$output->writeln(sprintf('ExApp %s deployed successfully', $appId));
			return 0;
		} else {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $startResult['error'] ?? $createResult['error']));
		}
		return 1;
	}
}
