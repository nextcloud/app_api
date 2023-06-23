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

use OCA\AppEcosystemV2\Db\ExApp;
use OCP\Http\Client\IResponse;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use Symfony\Component\Console\Question\Question;

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
		$this->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Enable ExApp after registration');
		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force scopes approval');
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
			// TODO: Remove. Temporal override for testing
			$exApp->setAppid('nc_py_api');
			$exApp->setSecret('tC6vkwPhcppjMykD1r0n9NlI95uJMBYjs5blpIcA1PAdoPDmc5qoAjaBAkyocZ6EX1T8Pi+T5papEolTLxz3fJSPS8ffC4204YmggxPsbJdCkXHWNPHKWS9B+vTj2SIV');

			$output->writeln('ExApp successfully registered.');
			$enabled = (bool) $input->getOption('enabled');
			if ($enabled) {
				if ($this->service->enableExApp($exApp)) {
					$exAppEnabled = $this->service->aeRequestToExApp(null, '', $exApp, '/enabled', 'PUT', ['enabled' => true]);
					if ($exAppEnabled instanceof IResponse) {
						$response = json_decode($exAppEnabled->getBody(), true);
						if (isset($response['error']) && count($response['error']) === 0) {
							$output->writeln('ExApp successfully enabled.');
						} else {
							$output->writeln('Failed to enable ExApp. Error: ' . $response['error']);
							return 1;
						}
					} else if (isset($exAppEnabled['error'])) {
						$output->writeln('Failed to enable ExApp. Error: ' . $exAppEnabled['error']);
						return 1;
					}
				} else {
					$output->writeln('Failed to enable ExApp.');
					return 1;
				}
			}

			$requestedExAppScopeGroups = $this->getRequestedExAppScopeGroups($output, $exApp);
			$forceScopes = (bool) $input->getOption('force-scopes');
			$confirmScopes = $forceScopes;
			$confirmOptionalScopes = $forceScopes;
			if (!$forceScopes && $input->isInteractive()) {
				/** @var QuestionHelper $helper */
				$helper = $this->getHelper('question');

				// Prompt to approve required ExApp scopes
				$output->writeln('ExApp requested required scopes: ' . implode(', ', $this->service->mapScopeGroupsToNames($requestedExAppScopeGroups['required'])));
				$question = new Question('Do you want to approve it? [y/N] ', 'y');
				$confirmQuestionRes = $helper->ask($input, $output, $question);
				$confirmScopes = strtolower($confirmQuestionRes) === 'y';

				// Prompt to approve optional ExApp scopes
				if ($confirmScopes && count($requestedExAppScopeGroups['optional']) > 0) {
					$output->writeln('ExApp requested optional scopes: ' . implode(', ', $this->service->mapScopeGroupsToNames($requestedExAppScopeGroups['optional'])));
					$question = new Question('Do you want to approve it? [y/N] ', 'y');
					$confirmQuestionRes = $helper->ask($input, $output, $question);
					$confirmOptionalScopes = strtolower($confirmQuestionRes) === 'y';
				}
			}
			if (!$confirmScopes) {
				$output->writeln('ExApp scopes not approved.');
				return 0;
			}

			// TODO: Remove. Temporal override for testing
			$exApp->setAppid($appId);

			$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['required']);
			if ($confirmOptionalScopes) {
				$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['optional'], false);
			}

			return 0;
		}
		$output->writeln('Failed to register ExApp.');
		return 1;
	}

	private function registerExAppScopes($output, ExApp $exApp, array $requestedExAppScopeGroups, bool $required = true): void {
		$scopeType = $required ? 'required' : 'optional';
		$registeredScopeGroups = [];
		foreach ($requestedExAppScopeGroups as $scopeGroup) {
			if ($this->service->setExAppScopeGroup($exApp, $scopeGroup)) {
				$registeredScopeGroups[] = $scopeGroup;
			} else {
				$output->writeln('Failed to set ' . $scopeType . ' ExApp scope group: ' . $scopeGroup);
			}
		}
		if (count($registeredScopeGroups) > 0) {
			$output->writeln('ExApp ' . $scopeType . ' scope groups successfully set: ' . implode(', ', $this->service->mapScopeGroupsToNames($registeredScopeGroups)));
		}
	}

	private function getRequestedExAppScopeGroups(OutputInterface $output, ExApp $exApp): ?array {
		$response = $this->service->aeRequestToExApp(null, '', $exApp, '/scopes', 'GET');
		if (!$response instanceof IResponse && isset($response['error'])) {
			$output->writeln('Failed to get ex-app scope groups: ' . $response['error']);
			return null;
		}
		if ($response->getStatusCode() === 200) {
			return json_decode($response->getBody(), true);
		}
		return null;
	}
}
