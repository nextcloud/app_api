<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Command\ExApp\Scopes;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\ExAppApiScopeService;
use OCA\AppEcosystemV2\Service\ExAppScopesService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListScopes extends Command {
	private AppEcosystemV2Service $service;
	private ExAppScopesService $exAppScopeService;
	private ExAppApiScopeService $exAppApiScopeService;

	public function __construct(
		AppEcosystemV2Service $service,
		ExAppScopesService    $exAppScopeService,
		ExAppApiScopeService  $exAppApiScopeService,
	) {
		parent::__construct();

		$this->service = $service;
		$this->exAppScopeService = $exAppScopeService;
		$this->exAppApiScopeService = $exAppApiScopeService;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:scopes:list');
		$this->setDescription('List ExApp granted scopes');

		$this->addArgument('appid', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found.', $appId));
			return 2;
		}

		$scopes = $this->exAppScopeService->getExAppScopes($exApp);
		if (empty($scopes)) {
			$output->writeln(sprintf('No scopes granted for ExApp %s', $appId));
			return 0;
		}

		$output->writeln(sprintf('ExApp %s scopes:', $exApp->getAppid()));
		$mappedScopes = array_unique($this->exAppApiScopeService->mapScopeGroupsToNames($scopes));
		$output->writeln(join(', ', $mappedScopes));
		return 0;
	}
}
