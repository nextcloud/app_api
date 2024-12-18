<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Enable extends Command {

	public function __construct(
		private readonly AppAPIService 		 $service,
		private readonly ExAppService        $exAppService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:enable');
		$this->setDescription('Enable registered external app');

		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->exAppService->getExApp($appId);

		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found. Failed to enable.', $appId));
			return 1;
		}
		if ($exApp->getEnabled()) {
			$output->writeln(sprintf('ExApp %s already enabled.', $appId));
			return 0;
		}

		if ($this->service->enableExApp($exApp)) {
			$output->writeln(sprintf('ExApp %s successfully enabled.', $appId));
			return 0;
		}
		$output->writeln(sprintf('Failed to enable ExApp %s.', $appId));
		return 1;
	}
}
