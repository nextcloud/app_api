<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp\Users;

use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ExAppUsersService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListUsers extends Command {

	public function __construct(
		private readonly ExAppService      $service,
		private readonly ExAppUsersService $exAppUserService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:users:list');
		$this->setDescription('List ExApp authorized users');

		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found.', $appId));
			return 2;
		}

		$exAppUsers = $this->exAppUserService->getExAppUsers($exApp);
		if (empty($exAppUsers)) {
			$output->writeln(sprintf('ExApp %s has no authorized users.', $appId));
			return 0;
		}

		$output->writeln(sprintf('ExApp %s authorized users:', $appId));
		foreach ($exAppUsers as $exAppUser) {
			$output->writeln($exAppUser->getUserid() !== '' ? $exAppUser->getUserid() : '[system user]');
		}

		return 0;
	}
}
