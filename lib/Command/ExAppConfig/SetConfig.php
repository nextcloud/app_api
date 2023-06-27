<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Command\ExAppConfig;

use OCA\AppEcosystemV2\Service\ExAppConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

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
			return Command::FAILURE;
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
					return Command::FAILURE;
				}
			} else {
				$exAppConfig = $this->exAppConfigService->getAppConfig($appId, $configKey);
				if ($exAppConfig === null) {
					$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' not found');
					return Command::FAILURE;
				}
				$exAppConfig->setConfigvalue($value);
				$exAppConfig->setSensitive((int) $sensitive);
				if ($this->exAppConfigService->updateAppConfigValue($exAppConfig) !== 1) {
					$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' not updated');
					return Command::FAILURE;
				}
			}
			$sensitiveMsg = $sensitive ? '[sensitive]' : '';
			$output->writeln('ExApp ' . $appId . ' config ' . $configKey . ' set to ' . $value . ' ' . $sensitiveMsg);
			return Command::SUCCESS;
		}

		$output->writeln('ExApp ' . $appId . ' is disabled');
		return Command::FAILURE;
	}
}
