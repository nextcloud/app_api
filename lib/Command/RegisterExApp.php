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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

class RegisterExApp extends Command {
	private AppEcosystemV2Service $service;

	public function __construct(AppEcosystemV2Service $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:register');
		$this->setDescription('Register external app');
		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('version', InputArgument::REQUIRED);
		$this->addArgument('name', InputArgument::REQUIRED);
		$this->addArgument('config', InputArgument::REQUIRED);
		$this->addArgument('scope_group', InputArgument::REQUIRED);
		$this->addArgument('enabled', InputArgument::OPTIONAL);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$version = $input->getArgument('version');
		$name = $input->getArgument('name');
		$config = $input->getArgument('config');
		if ($this->service->getExApp($appId) !== null) {
			$output->writeln('ExApp ' . $appId . ' already registered.');
			return 0;
		}
		$exApp = $this->service->registerExApp($appId, [
			'version' => $version,
			'name' => $name,
			'config' => $config,
		]);
		if ($exApp !== null) {
			$output->writeln('Ex-app successfully registered.');
			$enabled = boolval($input->getArgument('enabled'));
			if ($enabled) {
				if ($this->service->enableExApp($exApp)) {
					$output->writeln('ExApp successfully enabled.');
				} else {
					$output->writeln('Failed to enable ex-app.');
				}
			}
			$scopeGroup = intval($input->getArgument('scope_group'));
			if ($this->service->setExAppScopeGroup($exApp, $scopeGroup)) {
				$output->writeln('ExApp scope group successfully set.');
			} else {
				$output->writeln('Failed to set ex-app scope group.');
			}
			return 0;
		}
		$output->writeln('Failed to register ex-app.');
		return 1;
	}
}
