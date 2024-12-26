<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\Db\ExAppMapper;

use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListExApps extends Command {
	public function __construct(private readonly ExAppMapper $mapper) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:list');
		$this->setDescription('List ExApps');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$exApps = $this->mapper->findAll();
			$output->writeln('<info>ExApps:</info>');
			foreach ($exApps as $exApp) {
				$enabled = $exApp->getEnabled() ? 'enabled' : 'disabled';
				$output->writeln($exApp->getAppid() . ' (' . $exApp->getName() . '): ' . $exApp->getVersion() . ' [' . $enabled . ']');
			}
		} catch (Exception) {
			$output->writeln('<error>Failed to get list of ExApps</error>');
			return 1;
		}
		return 0;
	}
}
