<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\DeployActions\DockerActions;
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

	public function __construct(
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		DockerActions $dockerActions,
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
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

		$envParams = $input->getOption('env');

		$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $infoXml, [
			'env_options' => $envParams,
		]);

		[$pullResult, $createResult, $startResult] = $this->dockerActions->deployExApp($daemonConfig, $deployParams);

		if (isset($pullResult['error'])) {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $pullResult['error']));
			return 1;
		}

		if (!isset($startResult['error']) && isset($createResult['Id'])) {
			if (!$this->dockerActions->healthcheckContainer($createResult['Id'], $daemonConfig)) {
				$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, 'Container healthcheck failed.'));
				return 1;
			}

			// TODO: Remove resultOutput
			$resultOutput = [
				'appid' => $appId,
				'name' => (string) $infoXml->name,
				'daemon_config_name' => $daemonConfigName,
				'version' => (string) $infoXml->version,
				'secret' => explode('=', $deployParams['container_params']['env'][1])[1],
				'host' => $this->dockerActions->resolveDeployExAppHost($appId, $daemonConfig),
				'port' => explode('=', $deployParams['container_params']['env'][7])[1],
				'protocol' => (string) ($infoXml->xpath('ex-app/protocol')[0] ?? 'http'),
				'system_app' => (bool) ($infoXml->xpath('ex-app/system')[0] ?? false),
			];

			if ($this->service->heartbeatExApp($resultOutput)) {
				$output->writeln(json_encode($resultOutput, JSON_UNESCAPED_SLASHES));
				return 0;
			}

			$output->writeln(sprintf('ExApp %s heartbeat check failed. Make sure container started and initialized correctly.', $appId));
		} else {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $startResult['error'] ?? $createResult['error']));
		}
		return 1;
	}
}
