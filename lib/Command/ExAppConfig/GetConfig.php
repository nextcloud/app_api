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
			$output->writeln(sprintf('ExApp % not found.', $appId));
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
