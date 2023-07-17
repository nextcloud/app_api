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

namespace OCA\AppEcosystemV2\Command\Scopes;

use OCA\AppEcosystemV2\Service\ExAppApiScopeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class ListApiScopes extends Command {
	private ExAppApiScopeService $service;

	public function __construct(ExAppApiScopeService $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:scopes:list');
		$this->setDescription('List registered API scopes');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$scopes = $this->service->getExAppApiScopes();
		if (empty($scopes)) {
			$output->writeln('No API scopes registered');
			return 0;
		}

		$output->writeln('Registered API scopes:');
		foreach ($scopes as $scope) {
			$output->writeln(sprintf('  %s. %s [%s]', $scope->getScopeGroup(), $scope->getApiRoute(), $scope->getName()));
		}
		return 0;
	}
}
