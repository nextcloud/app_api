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

class ListConfig extends Command {

	private const SENSITIVE_VALUE = '***REMOVED SENSITIVE VALUE***';

	public function __construct(
		private readonly ExAppService       $service,
		private readonly ExAppConfigService $appConfigService
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:config:list');
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
