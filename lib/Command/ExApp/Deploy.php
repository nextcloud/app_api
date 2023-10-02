<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;

use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Deploy extends Command {
	private AppAPIService $service;
	private DaemonConfigService $daemonConfigService;
	private DockerActions $dockerActions;
	private IConfig $config;

	public function __construct(
		AppAPIService       $service,
		DaemonConfigService $daemonConfigService,
		DockerActions       $dockerActions,
		IConfig             $config,
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
		$this->config = $config;
	}

	protected function configure() {
		$this->setName('app_api:app:deploy');
		$this->setDescription('Deploy ExApp on configured daemon');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-name', InputArgument::OPTIONAL);

		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, '[required] Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('env', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Docker container environment variables', []);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$exApp = $this->service->getExApp($appId);
		if ($exApp !== null) {
			$output->writeln(sprintf('ExApp %s already registered.', $appId));
			return 2;
		}

		$pathToInfoXml = $input->getOption('info-xml');
		if ($pathToInfoXml !== null) {
			$infoXml = simplexml_load_string(file_get_contents($pathToInfoXml));
		} else {
			$infoXml = $this->service->getLatestExAppInfoFromAppstore($appId);
			// TODO: Add default release signature check and use of release archive download and info.xml file extraction
		}

		if ($infoXml === false) {
			$output->writeln(sprintf('Failed to load info.xml from %s', $pathToInfoXml));
			return 2;
		}
		if ($appId !== (string) $infoXml->id) {
			$output->writeln(sprintf('ExApp appid %s does not match appid in info.xml (%s)', $appId, $infoXml->id));
			return 2;
		}

		$defaultDaemonConfigName = $this->config->getAppValue(Application::APP_ID, 'default_daemon_config', '');
		$daemonConfigName = $input->getArgument('daemon-config-name');
		if (!isset($daemonConfigName) && $defaultDaemonConfigName !== '') {
			$daemonConfigName = $defaultDaemonConfigName;
		}
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
			if (!$this->dockerActions->healthcheckContainer($this->dockerActions->buildExAppContainerName($appId), $daemonConfig)) {
				$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, 'Container healthcheck failed.'));
				return 1;
			}

			$exAppUrlParams = [
				'protocol' => (string) ($infoXml->xpath('ex-app/protocol')[0] ?? 'http'),
				'host' => $this->dockerActions->resolveDeployExAppHost($appId, $daemonConfig),
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
