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

class SetConfig extends Command {

	public function __construct(
		private readonly ExAppService		$service,
		private readonly ExAppConfigService $exAppConfigService
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:config:set');
		$this->setDescription('Set ExApp config');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('configkey', InputArgument::REQUIRED);

		$this->addOption('value', null, InputOption::VALUE_REQUIRED);
		$this->addOption('sensitive', null, InputOption::VALUE_OPTIONAL, 'Sensitive config value', null);
		$this->addOption('update-only', null, InputOption::VALUE_NONE, 'Only update config, if not exists - do not create');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found', $appId));
			return 1;
		}

		$configKey = $input->getArgument('configkey');
		$value = $input->getOption('value');
		$isSensitive = $input->getOption('sensitive');
		$sensitive = (int) filter_var($isSensitive, FILTER_VALIDATE_BOOLEAN);
		$updateOnly = $input->getOption('update-only');

		$exAppConfig = $this->exAppConfigService->getAppConfig($appId, $configKey);
		if (!$updateOnly) {
			if ($exAppConfig !== null) {
				$output->writeln(sprintf('ExApp %s config %s already exists. Use --update-only flag.', $appId, $configKey));
				return 1;
			}

			$exAppConfig = $this->exAppConfigService->setAppConfigValue($appId, $configKey, $value, $sensitive);
			if ($exAppConfig === null) {
				$output->writeln(sprintf('ExApp %s config %s not found', $appId, $configKey));
				return 1;
			}
		} else {
			if ($exAppConfig === null) {
				$output->writeln(sprintf('ExApp %s config %s not found', $appId, $configKey));
				return 1;
			}
			$exAppConfig->setConfigvalue($value);
			if ($isSensitive !== null) {
				$exAppConfig->setSensitive($sensitive);
			}
			if ($this->exAppConfigService->updateAppConfigValue($exAppConfig) === null) {
				$output->writeln(sprintf('ExApp %s config %s not updated', $appId, $configKey));
				return 1;
			}
		}

		$sensitiveMsg = $exAppConfig->getSensitive() === 1 ? '[sensitive]' : '';
		$output->writeln(sprintf('ExApp %s config %s set to %s %s', $appId, $configKey, $value, $sensitiveMsg));
		return 0;
	}
}
