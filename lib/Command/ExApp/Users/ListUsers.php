<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp\Users;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppUsersService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListUsers extends Command {
	private AppEcosystemV2Service $service;
	private ExAppUsersService $exAppUserService;

	public function __construct(
		AppEcosystemV2Service $service,
		ExAppUsersService     $exAppUserService,
	) {
		parent::__construct();

		$this->service = $service;
		$this->exAppUserService = $exAppUserService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:users:list');
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
