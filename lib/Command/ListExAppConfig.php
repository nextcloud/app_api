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

namespace OCA\AppEcosystemV2\Command;

use OCA\AppEcosystemV2\Service\ExAppConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

class ListExAppConfig extends Command {
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
			$output->writeln('ExApp ' . $appId . ' not found');
			return 1;
		}
		if ($exApp->getEnabled()) {
			$exAppConfigs = $this->appConfigService->getAllAppConfig($exApp->getAppid());
			$private = $input->getOption('private');
			$output->writeln('ExApp ' . $exApp->getAppid() . ' configs:');
			$appConfigs = [];
			foreach ($exAppConfigs as $exAppConfig) {
				$appConfigs[$exAppConfig->getAppid()][$exAppConfig->getConfigkey()] = (!$private && $exAppConfig->getSensitive() ? $exAppConfig->getConfigvalue() : self::SENSITIVE_VALUE);
			}
			$output->writeln(json_encode($appConfigs, JSON_PRETTY_PRINT));
			return 0;
		}
		return 1;
	}
}
