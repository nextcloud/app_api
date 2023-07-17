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

namespace OCA\AppEcosystemV2\Command\ExApp\Scopes;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppApiScopeService;
use OCA\AppEcosystemV2\Service\ExAppScopesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class ListScopes extends Command {
	private AppEcosystemV2Service $service;
	private ExAppScopesService $exAppScopeService;
	private ExAppApiScopeService $exAppApiScopeService;

	public function __construct(
		AppEcosystemV2Service $service,
		ExAppScopesService    $exAppScopeService,
		ExAppApiScopeService  $exAppApiScopeService,
	) {
		parent::__construct();

		$this->service = $service;
		$this->exAppScopeService = $exAppScopeService;
		$this->exAppApiScopeService = $exAppApiScopeService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:scopes:list');
		$this->setDescription('List ExApp granted scopes');

		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found.', $appId));
			return 2;
		}

		$scopes = $this->exAppScopeService->getExAppScopes($exApp);
		if (empty($scopes)) {
			$output->writeln(sprintf('No scopes granted for ExApp %s', $appId));
			return 0;
		}

		$output->writeln(sprintf('ExApp %s scopes:', $exApp->getAppid()));
		$mappedScopes = array_unique($this->exAppApiScopeService->mapScopeGroupsToNames($scopes));
		$output->writeln(join(', ', $mappedScopes));
		return 0;
	}
}
