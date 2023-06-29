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

use OCA\AppEcosystemV2\Docker\DockerActions;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\DaemonConfigService;

class Deploy extends Command {
	private AppEcosystemV2Service $service;
	private DaemonConfigService $daemonConfigService;
	private DockerActions $dockerActions;

	public function __construct(
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		DockerActions $dockerActions
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:deploy');
		$this->setDescription('Deploy ExApp on configured daemon');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-id', InputArgument::REQUIRED);

		$this->addOption('image-name', null, InputOption::VALUE_REQUIRED, 'Docker image name');
		$this->addOption('image-tag', null, InputOption::VALUE_REQUIRED, 'Docker image tag');
		$this->addOption('container-name', null, InputOption::VALUE_REQUIRED, 'Docker container name. If not specified, appid will be used as container name.');
		$this->addOption('container-hostname', null, InputOption::VALUE_REQUIRED, 'Docker container hostname. If not specified, appid will be used as hostname.');
		$this->addOption('container-port', null, InputOption::VALUE_REQUIRED, 'Docker container port');

		$this->addUsage('test_app 1 --image-src=local --image-name=test_app --image-tag=latest');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to deploy.', $appId));
			return Command::FAILURE;
		}

		$daemonConfigId = (int) $input->getArgument('daemon-config-id');

		$imageParams = [
			'image_name' => $input->getOption('image-name'),
			'image_tag' => $input->getOption('image-tag') ?? 'latest',
		];

		$containerParams = [
			'name' => $input->getOption('container-name') ?? $appId,
			'hostname' => $input->getOption('container-hostname') ?? $appId,
			'port' => (int) $input->getOption('container-port'),
		];

		$daemonConfig = $this->daemonConfigService->getDaemonConfig($daemonConfigId);
		if ($daemonConfig === null) {
			$output->writeln('Daemon config not found.');
			return Command::FAILURE;
		}

		$output->writeln(sprintf('Deploying ExApp %s on daemon: %s', $appId, $daemonConfig->getDisplayName()));
		[$createResult, $startResult] = $this->dockerActions->deployExApp($daemonConfig, $imageParams, $containerParams);

		if (!isset($startResult['error']) && isset($createResult['Id'])) {
			$output->writeln(sprintf('ExApp %s deployed successfully.', $appId));
			$output->writeln(json_encode($startResult, JSON_PRETTY_PRINT));
			$containerInfo = $this->dockerActions->inspectContainer($createResult['Id']);
			$output->writeln(json_encode($containerInfo, JSON_PRETTY_PRINT));
			return Command::SUCCESS;
		} else {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $startResult['error']));
			return Command::FAILURE;
		}
	}
}
