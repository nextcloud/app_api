<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\DaemonConfigMapper;
use OCP\DB\Exception;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class DaemonUpdateGPUSRepairStep implements IRepairStep {
	public function __construct(
		private readonly DaemonConfigMapper $daemonConfigMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'AppAPI Daemons configuration GPU params update';
	}

	public function run(IOutput $output): void {
		$daemons = $this->daemonConfigMapper->findAll();
		$daemonsUpdated = 0;
		// Update manual-install daemons
		/** @var DaemonConfig $daemon */
		foreach ($daemons as $daemon) {
			$daemonsUpdated += $this->updateDaemonConfiguration($daemon);
		}
		$output->info(sprintf('Daemons configuration GPU params updated: %s', $daemonsUpdated));
	}

	private function updateDaemonConfiguration(DaemonConfig $daemonConfig): int {
		$updated = false;

		$deployConfig = $daemonConfig->getDeployConfig();
		if (isset($deployConfig['gpu'])) {
			if (filter_var($deployConfig['gpu'], FILTER_VALIDATE_BOOLEAN)) {
				$deployConfig['computeDevice'] = [
					'id' => 'cuda',
					'label' => 'CUDA (NVIDIA)',
				];
			} else {
				$deployConfig['computeDevice'] = [
					'id' => 'cpu',
					'label' => 'CPU',
				];
			}
			unset($deployConfig['gpu']);
			$daemonConfig->setDeployConfig($deployConfig);
			$updated = true;
		}

		if ($updated) {
			try {
				$this->daemonConfigMapper->update($daemonConfig);
				return 1;
			} catch (Exception $e) {
				$this->logger->error(
					sprintf('Failed to update Daemon config (%s: %s)',
						$daemonConfig->getAcceptsDeployId(), $daemonConfig->getName()),
					['exception' => $e]
				);
				return 0;
			}
		}
		return 0;
	}
}
