<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Command\ExApp;

use OCA\AppAPI\DeployActions\DockerActions;
use OCA\AppAPI\DeployActions\KubernetesActions;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Unregister extends Command {

	public function __construct(
		private readonly AppAPIService $service,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly DockerActions $dockerActions,
		private readonly KubernetesActions $kubernetesActions,
		private readonly ExAppService $exAppService,
		private readonly ExAppDeployOptionsService $exAppDeployOptionsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('app_api:app:unregister');
		$this->setDescription('Unregister external app');

		$this->addArgument('appid', InputArgument::REQUIRED);

		$this->addOption(
			'silent',
			null,
			InputOption::VALUE_NONE,
			'Print only minimum and only errors.');
		$this->addOption(
			'force',
			null,
			InputOption::VALUE_NONE,
			'Continue removal even if errors.');
		$this->addOption('keep-data', null, InputOption::VALUE_NONE, 'Keep ExApp data (volume) [deprecated, data is kept by default].');
		$this->addOption('rm-data', null, InputOption::VALUE_NONE, 'Remove ExApp data (persistent storage volume).');

		$this->addUsage('test_app');
		$this->addUsage('test_app --silent');
		$this->addUsage('test_app --rm-data');
		$this->addUsage('test_app --silent --force --rm-data');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');
		$silent = $input->getOption('silent');
		$force = $input->getOption('force');
		$rmData = $input->getOption('rm-data');

		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			if ($silent) {
				return 0;
			}
			$output->writeln(sprintf('ExApp %s not found. Failed to unregister.', $appId));
			return 1;
		}

		if ($exApp->getEnabled()) {
			if (!$this->service->disableExApp($exApp)) {
				if (!$silent) {
					$output->writeln(sprintf('Error during disabling %s ExApp.', $appId));
				}
				if (!$force) {
					return 1;
				}
			} elseif (!$silent) {
				$output->writeln(sprintf('ExApp %s successfully disabled.', $appId));
			}
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($exApp->getDaemonConfigName());
		if ($daemonConfig === null) {
			if (!$silent) {
				$output->writeln(
					sprintf('Failed to get ExApp %s DaemonConfig by name %s', $appId, $exApp->getDaemonConfigName())
				);
			}
			if (!$force) {
				return 1;
			}
		}
		if ($daemonConfig !== null) {
			if (DockerActions::isDockerSocketProxy($daemonConfig)) {
				$message = sprintf('Daemon "%s" uses Docker Socket Proxy, which is no longer supported, so container %s and its data volume are kept. Install the ExApp on a HaRP daemon on the same Docker host to reuse the data, or remove the container by hand.', $daemonConfig->getName(), $this->dockerActions->buildExAppContainerName($appId));
				$this->logger->warning($message);
				if (!$silent) {
					$output->writeln($message);
				}
			} elseif ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
				$this->dockerActions->initGuzzleClient($daemonConfig);
				if ($this->dockerActions->removeExApp($this->dockerActions->buildDockerUrl($daemonConfig), $exApp->getAppid(), removeData: $rmData)) {
					if (!$silent) {
						$output->writeln(sprintf('Failed to remove ExApp %s', $appId));
						$output->writeln('Hint: If the container was already removed manually, you can use the --force option to fully remove it from AppAPI.');
					}
					if (!$force) {
						return 1;
					}
				} else {
					if ($rmData) {
						$this->exAppService->logExAppDataDeleted($exApp);
					}
					if (!$silent) {
						$output->writeln(sprintf('ExApp %s successfully removed', $appId));
					}
				}
			} elseif ($daemonConfig->getAcceptsDeployId() === $this->kubernetesActions->getAcceptsDeployId()) {
				$this->kubernetesActions->initGuzzleClient($daemonConfig);
				$harpK8sUrl = $this->kubernetesActions->buildHarpK8sUrl($daemonConfig);

				// Check for stored multi-role configuration
				$rolesOption = $this->exAppDeployOptionsService->getDeployOption($exApp->getAppid(), 'k8s_service_roles');
				$roles = $rolesOption !== null ? $rolesOption->getValue() : [];

				if (!empty($roles) && is_array($roles)) {
					$removeResult = $this->kubernetesActions->removeAllRoles(
						$harpK8sUrl, $exApp->getAppid(), $roles, removeData: $rmData
					);
				} else {
					$removeResult = $this->kubernetesActions->removeExApp(
						$harpK8sUrl, $exApp->getAppid(), removeData: $rmData
					);
				}
				if ($removeResult) {
					if (!$silent) {
						$output->writeln(sprintf('Failed to remove K8s ExApp %s: %s', $appId, $removeResult));
						$output->writeln('Hint: If the K8s deployment was already removed manually, use --force to remove from AppAPI.');
					}
					if (!$force) {
						return 1;
					}
				} else {
					if ($rmData) {
						$this->exAppService->logExAppDataDeleted($exApp);
					}
					if (!$silent) {
						$output->writeln(sprintf('ExApp %s K8s resources successfully removed', $appId));
					}
				}
			}
		}

		if (!$this->exAppService->unregisterExApp($appId)) {
			if (!$silent) {
				$output->writeln(sprintf('Failed to unregister ExApp %s.', $appId));
			}
			if (!$force) {
				return 1;
			}
		}
		if (!$silent) {
			$output->writeln(sprintf('ExApp %s successfully unregistered.', $appId));
		}
		return 0;
	}
}
