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
use OCA\AppEcosystemV2\Service\DaemonConfigService;
use OCA\AppEcosystemV2\Service\ExAppApiScopeService;
use OCP\DB\Exception;
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
	private DaemonConfigService $daemonConfigService;
	private ExAppApiScopeService $exAppApiScopeService;

	public function __construct(
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		ExAppApiScopeService $exAppApiScopeService,
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->exAppApiScopeService = $exAppApiScopeService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:register');
		$this->setDescription('Register external app');

		$this->addArgument('deploy-json-output', InputArgument::REQUIRED, 'JSON output from deploy command');

		$this->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Enable ExApp after registration');
		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force scopes approval');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$deployJsonOutput = json_decode($input->getArgument('deploy-json-output'), true);
		if ($deployJsonOutput === null) {
			$output->writeln('Invalid deploy JSON output.');
			return Command::INVALID;
		}

		$appId = $deployJsonOutput['appid'];
		$version = $deployJsonOutput['version'];
		$name = $deployJsonOutput['name'];
		$daemonConfigId = (int) $deployJsonOutput['daemon_config_id'];
		$protocol = $deployJsonOutput['protocol'] ?? 'http';
		$port = (int) $deployJsonOutput['port'];
		$host = $deployJsonOutput['host'];
		$secret = $deployJsonOutput['secret'];

		if ($this->service->getExApp($appId) !== null) {
			$output->writeln(sprintf('ExApp %s already registered.', $appId));
			return Command::INVALID;
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfig($daemonConfigId);
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigId));
			return Command::INVALID;
		}

		$exApp = $this->service->registerExApp($appId, [
			'version' => $version,
			'name' => $name,
			'daemon_config_id' => $daemonConfigId,
			'protocol' => $protocol,
			'host' => $host,
			'port' => $port,
			'secret' => $secret,
		]);

		if ($exApp !== null) {
			$output->writeln(sprintf('ExApp %s successfully registered.', $appId));

			if (filter_var($deployJsonOutput['system_app'], FILTER_VALIDATE_BOOLEAN)) {
				try {
					$this->service->setupSystemAppFlag($exApp);
				}
				catch (Exception $e) {
					$output->writeln(sprintf('Error while setting app system flag: %s', $e->getMessage()));
					return Command::FAILURE;
				}
			}

			$requestedExAppScopeGroups = $this->getRequestedExAppScopeGroups($output, $exApp);
			if ($requestedExAppScopeGroups === null) {
				$output->writeln(sprintf('Failed to get requested ExApp scopes for %s.', $appId));
				// Fallback unregistering ExApp
				$this->service->unregisterExApp($exApp->getAppid());
				return Command::INVALID;
			}

			$forceScopes = (bool) $input->getOption('force-scopes');
			$confirmRequiredScopes = $forceScopes;
			$confirmOptionalScopes = $forceScopes;

			if (!$forceScopes && $input->isInteractive()) {
				/** @var QuestionHelper $helper */
				$helper = $this->getHelper('question');

				// Prompt to approve required ExApp scopes
				if (count($requestedExAppScopeGroups['required']) > 0) {
					$output->writeln(sprintf('ExApp %s requested required scopes: %s', $appId, implode(', ',
						$this->exAppApiScopeService->mapScopeGroupsToNames($requestedExAppScopeGroups['required']))));
					$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
					$confirmRequiredScopes = $helper->ask($input, $output, $question);
				}

				// Prompt to approve optional ExApp scopes
				if ($confirmRequiredScopes && count($requestedExAppScopeGroups['optional']) > 0) {
					$output->writeln(sprintf('ExApp %s requested optional scopes: %s', $appId, implode(', ',
							$this->exAppApiScopeService->mapScopeGroupsToNames($requestedExAppScopeGroups['optional']))));
					$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
					$confirmOptionalScopes = $helper->ask($input, $output, $question);
				}
			}

			if (!$confirmRequiredScopes && count($requestedExAppScopeGroups['required']) > 0) {
				$output->writeln(sprintf('ExApp %s required scopes not approved.', $appId));
				// Fallback unregistering ExApp
				$this->service->unregisterExApp($exApp->getAppid());
				return Command::SUCCESS;
			}

			if (count($requestedExAppScopeGroups['required']) > 0) {
				$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['required']);
			}
			if ($confirmOptionalScopes && count($requestedExAppScopeGroups['optional']) > 0) {
				$this->registerExAppScopes($output, $exApp, $requestedExAppScopeGroups['optional'], false);
			}

			$enabled = (bool) $input->getOption('enabled');
			if ($enabled) {
				if ($this->service->enableExApp($exApp)) {
					$output->writeln(sprintf('ExApp %s successfully enabled.', $appId));
				} else {
					$output->writeln(sprintf('Failed to enable ExApp %s.', $appId));
					// Fallback unregistering ExApp
					$this->service->unregisterExApp($exApp->getAppid());
					return Command::FAILURE;
				}
			}

			return Command::SUCCESS;
		}

		$output->writeln(sprintf('Failed to register ExApp %s.', $appId));
		return Command::FAILURE;
	}

	private function registerExAppScopes($output, ExApp $exApp, array $requestedExAppScopeGroups, bool $required = true): void {
		$scopeType = $required ? 'required' : 'optional';
		$registeredScopeGroups = [];
		foreach ($requestedExAppScopeGroups as $scopeGroup) {
			if ($this->service->setExAppScopeGroup($exApp, $scopeGroup)) {
				$registeredScopeGroups[] = $scopeGroup;
			} else {
				$output->writeln(sprintf('Failed to set %s ExApp scope group: %s', $scopeType, $scopeGroup));
			}
		}
		if (count($registeredScopeGroups) > 0) {
			$output->writeln(sprintf('ExApp %s %s scope groups successfully set: %s', $exApp->getAppid(), $scopeType, implode(', ',
					$this->exAppApiScopeService->mapScopeGroupsToNames($registeredScopeGroups))));
		}
	}

	private function getRequestedExAppScopeGroups(OutputInterface $output, ExApp $exApp): ?array {
		$response = $this->service->requestToExApp(null, null, $exApp, '/scopes', 'GET');
		if (!$response instanceof IResponse && isset($response['error'])) {
			$output->writeln(sprintf('Failed to get ExApp %s scope groups: %s', $exApp->getAppid(), $response['error']));
			return null;
		}
		if ($response->getStatusCode() === 200) {
			$this->service->updateExAppLastResponseTime($exApp);
			return json_decode($response->getBody(), true);
		}
		return null;
	}
}
