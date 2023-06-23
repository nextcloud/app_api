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

use OCP\Http\Client\IResponse;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

class UnregisterExApp extends Command {
	private AppEcosystemV2Service $service;

	public function __construct(AppEcosystemV2Service $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:unregister');
		$this->setDescription('Unregister external app');
		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		// TODO: Remove. Temporal override for testing
		$exApp->setAppid('nc_py_api');
		$exApp->setSecret('tC6vkwPhcppjMykD1r0n9NlI95uJMBYjs5blpIcA1PAdoPDmc5qoAjaBAkyocZ6EX1T8Pi+T5papEolTLxz3fJSPS8ffC4204YmggxPsbJdCkXHWNPHKWS9B+vTj2SIV');
		$exAppDisabled = $this->service->aeRequestToExApp(null, '', $exApp, '/enabled', 'POST', ['enabled' => 1]);
		if ($exAppDisabled instanceof IResponse) {
			$response = json_decode($exAppDisabled->getBody(), true);
			if (isset($response['error']) && count($response['error']) === 0) {
				$output->writeln('ExApp successfully disabled.');
			} else {
				$output->writeln('ExApp ' . $appId . ' not disabled. Failed to unregister.');
				return 1;
			}
		}
		// TODO: Remove. Temporal override for testing
		$exApp->setAppid($appId);
		$exApp = $this->service->unregisterExApp($appId);
		if ($exApp === null) {
			$output->writeln('ExApp ' . $appId . ' not found. Failed to unregister.');
			return 1;
		}
		if ($exApp->getAppid() === $appId) {
			$output->writeln('ExApp successfully unregistered.');
			$appScopes = $this->service->getExAppScopeGroups($exApp);
			foreach ($appScopes as $appScope) {
				$this->service->removeExAppScopeGroup($exApp, intval($appScope->getScopeGroup()));
			}
		}
		return 0;
	}
}
