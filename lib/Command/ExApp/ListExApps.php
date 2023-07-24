<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\Db\ExAppMapper;

use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListExApps extends Command {
	private ExAppMapper $mapper;

	public function __construct(ExAppMapper $mapper) {
		parent::__construct();

		$this->mapper = $mapper;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:list');
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
