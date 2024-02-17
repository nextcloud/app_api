<?php

declare(strict_types=1);

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\Db\ExAppScope;
use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppApiScopeService;
use OCA\AppAPI\Service\ExAppScopesService;

use OCA\AppAPI\Service\ExAppService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Update extends Command {

	public function __construct(
		private readonly AppAPIService  	  $service,
		private readonly ExAppService         $exAppService,
		private readonly ExAppScopesService   $exAppScopeService,
		private readonly ExAppApiScopeService $exAppApiScopeService,
		private readonly DaemonConfigService  $daemonConfigService,
		private readonly DockerActions        $dockerActions,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:update');
		$this->setDescription('Update ExApp');

		$this->addArgument('appid', InputArgument::REQUIRED);

		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, 'Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('json-info', null, InputOption::VALUE_REQUIRED, 'ExApp info.xml in JSON format');
		$this->addOption('force-scopes', null, InputOption::VALUE_NONE, 'Force new ExApp scopes approval');
		$this->addOption('wait-finish', null, InputOption::VALUE_NONE, 'Wait until finish');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$appInfo = $this->exAppService->getAppInfo(
			$appId, $input->getOption('info-xml'), $input->getOption('json-info')
		);
		if (isset($appInfo['error'])) {
			$output->writeln($appInfo['error']);
			return 1;
		}
		$appId = $appInfo['id'];  # value from $appInfo should have higher priority

		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$output->writeln(sprintf('ExApp %s not found.', $appId));
			return 1;
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found', $exApp->getDaemonConfigName()));
		}
		if ($daemonConfig->getAcceptsDeployId() === 'manual-install') {
			$output->writeln('For "manual-install" deployId update is done manually');
			return 1;
		}

		if ($exApp->getVersion() === $appInfo['version']) {
			$output->writeln(sprintf('ExApp %s is already updated (%s)', $appId, $appInfo['version']));
			return 2;
		}

		if ($exApp->getEnabled()) {
			if (!$this->service->disableExApp($exApp)) {
				$this->exAppService->disableExAppInternal($exApp);
			} else {
				$output->writeln(sprintf('ExApp %s disabled.', $appId));
			}
		}

		$appInfo['port'] = $exApp->getPort();
		$appInfo['secret'] = $exApp->getSecret();
		$auth = [];
		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$this->dockerActions->initGuzzleClient($daemonConfig); // Required init
			$containerInfo = $this->dockerActions->inspectContainer($this->dockerActions->buildDockerUrl($daemonConfig), $this->dockerActions->buildExAppContainerName($appId));
			if (isset($containerInfo['error'])) {
				$output->writeln(sprintf('Failed to inspect old ExApp %s container. Error: %s', $appId, $containerInfo['error']));
				return 1;
			}
			$deployParams = $this->dockerActions->buildDeployParams($daemonConfig, $appInfo, [
				'container_info' => $containerInfo,
			]);
			[$pullResult, $stopResult, $removeResult, $createResult, $startResult] = $this->dockerActions->updateExApp($exApp, $daemonConfig, $deployParams);

			if (isset($pullResult['error'])) {
				$output->writeln(sprintf('ExApp %s update failed. Error: %s', $appId, $pullResult['error']));
				return 1;
			}

			if (isset($stopResult['error']) || isset($removeResult['error'])) {
				$output->writeln(sprintf('Failed to remove old ExApp %s container (id: %s). Error: %s', $appId, $containerInfo['Id'], $stopResult['error'] ?? $removeResult['error'] ?? null));
				return 1;
			}

			if (!isset($startResult['error']) && isset($createResult['Id'])) {
				if (!$this->dockerActions->healthcheckContainer($this->dockerActions->buildExAppContainerName($appId), $daemonConfig)) {
					$output->writeln(sprintf('ExApp %s update failed. Error: %s', $appId, 'Container healthcheck failed.'));
					return 1;
				}

				$exAppUrl = $this->dockerActions->resolveExAppUrl(
					$appId,
					$daemonConfig->getProtocol(),
					$daemonConfig->getHost(),
					$daemonConfig->getDeployConfig(),
					(int) $deployParams['container_params']['port'],
					$auth,
				);
			} else {
				$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $startResult['error'] ?? $createResult['error']));
				return 3;
			}
		} else {
			$output->writeln(sprintf('Daemon config %s actions for %s not found.', $daemonConfig->getName(), $daemonConfig->getAcceptsDeployId()));
			return 2;
		}

		if (!$this->service->heartbeatExApp($exAppUrl, $auth)) {
			$output->writeln(sprintf('ExApp %s heartbeat check failed. Make sure container started and configured correctly to be reachable by Nextcloud.', $appId));
			return 1;
		}

		$output->writeln(sprintf('ExApp %s container successfully updated.', $appId));

		$exAppInfo = $this->dockerActions->loadExAppInfo($appId, $daemonConfig);
		if (!$this->exAppService->updateExAppInfo($exApp, $exAppInfo)) {
			$output->writeln(sprintf('Failed to update ExApp %s info', $appId));
			return 1;
		}

		// Default scopes approval process (compare new ExApp scopes)
		$currentExAppScopes = array_map(function (ExAppScope $exAppScope) {
			return $exAppScope->getScopeGroup();
		}, $this->exAppScopeService->getExAppScopes($exApp));
		// Prepare for prompt of newly requested ExApp scopes
		$requiredScopes = $this->compareExAppScopes($currentExAppScopes, $appInfo['external-app']['scopes']);

		$forceScopes = (bool) $input->getOption('force-scopes');
		$confirmScopes = $forceScopes;

		if (!$forceScopes && $input->isInteractive()) {
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');

			if (count($requiredScopes) > 0) {
				$output->writeln(sprintf('ExApp %s requested scopes: %s', $appId, implode(', ',
					$this->exAppApiScopeService->mapScopeGroupsToNames($requiredScopes))));
				$question = new ConfirmationQuestion('Do you want to approve it? [y/N] ', false);
				$confirmScopes = $helper->ask($input, $output, $question);
			} else {
				$confirmScopes = true;
			}
		}

		if (!$confirmScopes && count($requiredScopes) > 0) {
			$output->writeln(sprintf('ExApp %s required scopes not approved. Failed to finish ExApp update.', $appId));
			return 1;
		}

		if (!$this->exAppScopeService->registerExAppScopes(
			$exApp, $this->exAppApiScopeService->mapScopeNamesToNumbers($appInfo['external-app']['scopes']))
		) {
			$output->writeln(sprintf('Failed to update ExApp %s scopes.', $appId));
			return 1;
		}

		$this->service->dispatchExAppInitInternal($exApp);
		if ($input->getOption('wait-finish')) {
			$error = $this->exAppService->waitInitStepFinish($appId);
			if ($error) {
				$output->writeln($error);
				return 1;
			}
		}
		$output->writeln(sprintf('ExApp %s successfully updated.', $appId));
		return 0;
	}

	/**
	 * Compare ExApp scopes and return difference (new requested)
	 *
	 * @param array $currentExAppScopes
	 * @param array $newExAppScopes
	 * @return array
	 */
	private function compareExAppScopes(array $currentExAppScopes, array $newExAppScopes): array {
		return array_values(array_diff($this->exAppApiScopeService->mapScopeNamesToNumbers($newExAppScopes), $currentExAppScopes));
	}
}
