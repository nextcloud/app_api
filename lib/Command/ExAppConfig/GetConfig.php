<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExAppConfig;

use OCA\AppAPI\Service\ExAppConfigService;

use OCA\AppAPI\Service\ExAppService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GetConfig extends Command {

	public function __construct(
		private readonly ExAppService       $service,
		private readonly ExAppConfigService $exAppConfigService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:config:get');
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
				$output->writeln((string)$defaultValue);
				return 0;
			}
			$output->writeln(sprintf('ExApp %s config %s not found', $appId, $configKey));
			return 1;
		}

		$value = $exAppConfig->getConfigvalue() ?? $defaultValue;

		$output->writeln((string)$value);
		return 0;
	}
}
