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

class SetConfig extends Command {
	private AppEcosystemV2Service $service;
	private ExAppConfigService $exAppConfigService;

	public function __construct(AppEcosystemV2Service $service, ExAppConfigService $exAppConfigService) {
		parent::__construct();

		$this->service = $service;
		$this->exAppConfigService = $exAppConfigService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:config:set');
		$this->setDescription('Set ExApp config');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('configkey', InputArgument::REQUIRED);

		$this->addOption('value', null, InputOption::VALUE_REQUIRED);
		$this->addOption('sensitive', null, InputOption::VALUE_NONE, 'Sensitive config value');
		$this->addOption('update-only', null, InputOption::VALUE_NONE, 'Only update config, if not exists - do not create');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln('ExApp ' . $appId . ' not found');
			return 1;
		}

		if ($exApp->getEnabled()) {
			$configKey = $input->getArgument('configkey');
			$value = $input->getOption('value');
			$sensitive = $input->getOption('sensitive');
			$updateOnly = $input->getOption('update-only');

			if (!$updateOnly) {
				$exAppConfig = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $value, (int) $sensitive);
				if ($exAppConfig === null) {
					$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' not found');
					return 1;
				}
			} else {
				$exAppConfig = $this->exAppConfigService->getAppConfig($appId, $configKey);
				if ($exAppConfig === null) {
					$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' not found');
					return 1;
				}
				$exAppConfig->setConfigvalue($value);
				$exAppConfig->setSensitive((int) $sensitive);
				if ($this->exAppConfigService->updateAppConfigValue($exAppConfig) !== 1) {
					$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' not updated');
					return 1;
				}
			}
			$sensitiveMsg = $sensitive ? '[sensitive]' : '';
			$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' set to ' . $value . ' ' . $sensitiveMsg);
			return 0;
		}

		$output->writeln('ExApp ' . $appId . ' is disabled');
		return 1;
	}
}
