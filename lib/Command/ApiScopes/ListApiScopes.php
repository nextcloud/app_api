<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ApiScopes;

use OCA\AppAPI\Service\ExAppApiScopeService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListApiScopes extends Command {
	private ExAppApiScopeService $service;

	public function __construct(ExAppApiScopeService $service) {
		parent::__construct();

		$this->service = $service;
	}

	protected function configure() {
		$this->setName('app_api:scopes:list');
		$this->setDescription('List registered API scopes');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$scopes = $this->service->getExAppApiScopes();
		if (empty($scopes)) {
			$output->writeln('No API scopes registered');
			return 0;
		}

		$output->writeln('Registered API scopes:');
		foreach ($scopes as $scope) {
			$output->writeln(sprintf('  %s. %s [%s]', $scope->getScopeGroup(), $scope->getApiRoute(), $scope->getName()));
		}
		return 0;
	}
}
