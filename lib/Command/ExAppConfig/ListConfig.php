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

class ListConfig extends Command {
	private AppEcosystemV2Service $service;
	private ExAppConfigService $appConfigService;

	private const SENSITIVE_VALUE = '***REMOVED SENSITIVE VALUE***';

	public function __construct(AppEcosystemV2Service $service, ExAppConfigService $appConfigService) {
		parent::__construct();

		$this->service = $service;
		$this->appConfigService = $appConfigService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:config:list');
		$this->setDescription('List ExApp configs');
		$this->addArgument('appid', InputArgument::REQUIRED);

		$this->addOption('private', null, InputOption::VALUE_NONE, 'Include sensitive ExApp config values like secrets, passwords, etc.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found.', $appId));
			return 1;
		}

		$exAppConfigs = $this->appConfigService->getAllAppConfig($exApp->getAppid());
		$private = $input->getOption('private');
		$output->writeln(sprintf('ExApp %s configs:', $exApp->getAppid()));
		$appConfigs = [];
		foreach ($exAppConfigs as $exAppConfig) {
			$appConfigs[$exAppConfig->getAppid()][$exAppConfig->getConfigkey()] = ($private && !$exAppConfig->getSensitive() ? $exAppConfig->getConfigvalue() : self::SENSITIVE_VALUE);
		}
		$output->writeln(json_encode($appConfigs, JSON_PRETTY_PRINT));
		return 0;
	}
}
