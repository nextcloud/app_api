<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExAppConfig;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppConfigService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetConfig extends Command {
	private AppEcosystemV2Service $service;
	private ExAppConfigService $exAppConfigService;

	public function __construct(AppEcosystemV2Service $service, ExAppConfigService $exAppConfigService) {
		parent::__construct();

		$this->service = $service;
		$this->exAppConfigService = $exAppConfigService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:config:get');
		$this->setDescription('Get ExApp config');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('configkey', InputArgument::REQUIRED);

		$this->addOption('default-value', null, InputOption::VALUE_REQUIRED, 'Default value if config not found');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found.', $appId));
			return 1;
		}

		$configKey = $input->getArgument('configkey');
		if ($configKey === null || $configKey === '') {
			$output->writeln('Config key is required.');
			return 1;
		}

		$exAppConfig = $this->exAppConfigService->getAppConfig($appId, $configKey);
		$defaultValue = $input->getOption('default-value');
		if ($exAppConfig === null) {
			if (isset($defaultValue)) {
				$output->writeln($defaultValue);
				return 0;
			}
			$output->writeln(sprintf('ExApp %s config %s not found', $appId, $configKey));
			return 1;
		}

		$value = $exAppConfig->getConfigvalue() ?? $defaultValue;

		$output->writeln($value);
		return 0;
	}
}
