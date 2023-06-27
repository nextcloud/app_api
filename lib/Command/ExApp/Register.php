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

use OCA\AppEcosystemV2\Db\ExApp;
use OCP\Http\Client\IResponse;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Register extends Command {
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

		$this->addOption('daemon-config-id', null, InputOption::VALUE_REQUIRED, 'Previously configured daemon config id for deployment');
		$this->addOption('host', null, InputOption::VALUE_REQUIRED);
		$this->addOption('port', null, InputOption::VALUE_REQUIRED);
		$this->addOption('secret', 's', InputOption::VALUE_REQUIRED, 'Secret for ExApp. If not passed - will be generated');
		$this->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Enable ExApp after registration');
		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force scopes approval');

		$this->addUsage('test_app 1.0.0 "Test app" --host http://host.docker.internal --port 9001 -e');
		$this->addUsage('test_app 1.0.0 "Test app" --host http://host.docker.internal --port 9001 -e --force-scopes');
		$this->addUsage('test_app 1.0.0 "Test app" --daemon-config-id 1 --host http://host.docker.internal --port 9001 -e --secret "***secret***" --force-scopes');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$version = $input->getArgument('version');
		$name = $input->getArgument('name');
		$daemonConfigId = $input->getOption('daemon-config-id');
		$host = $input->getOption('host');
		$port = $input->getOption('port');
		$secret = $input->getOption('secret');

		if ($this->service->getExApp($appId) !== null) {
			$output->writeln('ExApp ' . $appId . ' already registered.');
			return Command::INVALID;
		}

		$exApp = $this->service->registerExApp($appId, [
			'version' => $version,
			'name' => $name,
			'daemon_config_id' => (int) $daemonConfigId,
			'host' => $host,
			'port' => $port,
			'secret' => $secret,
		]);

		if ($exApp !== null) {
			$output->writeln('ExApp successfully registered.');

			$enabled = (bool) $input->getOption('enabled');
			if ($enabled) {
				if ($this->service->enableExApp($exApp)) {
					$exAppEnabled = $this->service->aeRequestToExApp(null, '', $exApp, '/enabled?enabled=1', 'PUT');
					if ($exAppEnabled instanceof IResponse) {
						$response = json_decode($exAppEnabled->getBody(), true);
						if (isset($response['error']) && strlen($response['error']) === 0) {
							$output->writeln('ExApp successfully enabled.');
						} else {
							$output->writeln('Failed to enable ExApp. Error: ' . $response['error']);
							$this->service->disableExApp($exApp);
							return Command::FAILURE;
						}
						$this->service->updateExAppLastResponseTime($exApp);
					} else if (isset($exAppEnabled['error'])) {
						$output->writeln('Failed to enable ExApp. Error: ' . $exAppEnabled['error']);
						$this->service->disableExApp($exApp);
						return Command::FAILURE;
					}
				} else {
					$output->writeln('Failed to enable ExApp.');
					return Command::FAILURE;
				}
			}

			$requestedExAppScopeGroups = $this->getRequestedExAppScopeGroups($output, $exApp);
			$forceScopes = (bool) $input->getOption('force-scopes');
			$confirmRequiredScopes = $forceScopes;
			$confirmOptionalScopes = $forceScopes;

			if (!$forceScopes && $input->isInteractive()) {
				/** @var QuestionHelper $helper */
				$helper = $this->getHelper('question');

				// Prompt to approve required ExApp scopes
				$output->writeln('ExApp requested required scopes: ' . implode(', ',
						$this->service->mapScopeGroupsToNames($requestedExAppScopeGroups['required'])));
				$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
				$confirmRequiredScopes = $helper->ask($input, $output, $question);

				// Prompt to approve optional ExApp scopes
				if ($confirmRequiredScopes && count($requestedExAppScopeGroups['optional']) > 0) {
					$output->writeln('ExApp requested optional scopes: ' . implode(', ',
							$this->service->mapScopeGroupsToNames($requestedExAppScopeGroups['optional'])));
					$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
					$confirmOptionalScopes = $helper->ask($input, $output, $question);
				}
			}

			if (!$confirmRequiredScopes) {
				$output->writeln('ExApp required scopes not approved.');
				return Command::SUCCESS;
			}

			$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['required']);
			if ($confirmOptionalScopes) {
				$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['optional'], false);
			}

			return Command::SUCCESS;
		}
		$output->writeln('Failed to register ExApp.');
		return Command::FAILURE;
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
			$output->writeln('ExApp ' . $scopeType . ' scope groups successfully set: ' . implode(', ',
					$this->service->mapScopeGroupsToNames($registeredScopeGroups)));
		}
	}

	private function getRequestedExAppScopeGroups(OutputInterface $output, ExApp $exApp): ?array {
		$response = $this->service->aeRequestToExApp(null, '', $exApp, '/scopes', 'GET');
		if (!$response instanceof IResponse && isset($response['error'])) {
			$output->writeln('Failed to get ExApp scope groups: ' . $response['error']);
			return null;
		}
		if ($response->getStatusCode() === 200) {
			$this->service->updateExAppLastResponseTime($exApp);
			return json_decode($response->getBody(), true);
		}
		return null;
	}
}
