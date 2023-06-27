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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\DaemonConfigService;

class Deploy extends Command {
	private DaemonConfigService $daemonConfigService;
	private DockerActions $dockerActions;

	public function __construct(DaemonConfigService $daemonConfigService, DockerActions $dockerActions) {
		parent::__construct();

		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:deploy');
		$this->setDescription('Deploy ExApp on configured daemon');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon_config_id', InputArgument::REQUIRED);

		$this->addOption('image-src', null, InputOption::VALUE_REQUIRED, 'Image source');
		$this->addOption('image-name', null, InputOption::VALUE_REQUIRED, 'Image name');
		$this->addOption('image-tag', null, InputOption::VALUE_REQUIRED, 'Image tag');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$daemonConfigId = (int) $input->getArgument('daemon_config_id');
		$imageSrc = $input->getOption('image-src');
		$imageName = $input->getOption('image-name');
		$imageTag = $input->getOption('image-tag');

		$daemonConfig = $this->daemonConfigService->getDaemonConfig($daemonConfigId);
		if ($daemonConfig === null) {
			$output->writeln('Daemon config not found.');
			return Command::FAILURE;
		}

		$output->writeln('Deploying ExApp ' . $appId . ' on daemon: ' . $daemonConfig->getDisplayName());
		$result = $this->dockerActions->deployExApp($appId, [
			'image_src' => $imageSrc,
			'image_name' => $imageName,
			'image_tag' => $imageTag,
		], $daemonConfig);

		if (!isset($result['error'])) {
			$output->writeln('ExApp deployed successfully.');
			return Command::SUCCESS;
		} else {
			$output->writeln('ExApp deployment failed: ' . $result['error']);
			return Command::FAILURE;
		}
	}
}
