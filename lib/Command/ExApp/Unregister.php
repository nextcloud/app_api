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

namespace OCA\AppEcosystemV2\Command\ExApp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

class Unregister extends Command {
	private AppEcosystemV2Service $service;

	public function __construct(AppEcosystemV2Service $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:unregister');
		$this->setDescription('Unregister external app');

		$this->addArgument('appid', InputArgument::REQUIRED);

		$this->addOption('silent', null, InputOption::VALUE_NONE, 'Unregister only from Nextcloud. Do not send request to external app.');

		$this->addUsage('test_app');
		$this->addUsage('test_app --silent');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to unregister.', $appId));
			return 1;
		}

		$silent = $input->getOption('silent');

		if (!$silent) {
			if ($this->service->disableExApp($exApp)) {
				$output->writeln(sprintf('ExApp %s successfully disabled.', $appId));
			} else {
				$output->writeln(sprintf('ExApp %s not disabled. Failed to disable.', $appId));
				return 1;
			}
		}

		$exApp = $this->service->unregisterExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('Failed to unregister ExApp %s.', $appId));
			return 1;
		}
		if ($exApp->getAppid() === $appId) {
			$appScopes = $this->service->getExAppScopeGroups($exApp);
			foreach ($appScopes as $appScope) {
				$this->service->removeExAppScopeGroup($exApp, intval($appScope->getScopeGroup()));
			}
		}

		$output->writeln(sprintf('ExApp %s successfully unregistered.', $appId));
		return 0;
	}
}
