<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExApp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Deploy extends Command {

	public function __construct(
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:deploy');
		$this->setDescription('Deploy ExApp on configured daemon');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-name', InputArgument::OPTIONAL);

		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, 'Path to ExApp info.xml file (url or local absolute path)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln("Use only `register` command, this command is deprecated.");
		return 0;
	}
}
