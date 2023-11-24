<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp\Scopes;

use OCA\AppAPI\Db\ExAppScope;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppApiScopeService;
use OCA\AppAPI\Service\ExAppScopesService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListScopes extends Command {

	public function __construct(
		private AppAPIService        $service,
		private ExAppScopesService   $exAppScopeService,
		private ExAppApiScopeService $exAppApiScopeService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:scopes:list');
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
		$mappedScopes = array_unique($this->exAppApiScopeService->mapScopeGroupsToNames(array_map(function (ExAppScope $scope) {
			return intval($scope->getScopeGroup());
		}, $scopes)));
		$output->writeln(join(', ', $mappedScopes));
		return 0;
	}
}
