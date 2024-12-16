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

class DaemonUpdateV2RepairStep implements IRepairStep {
	public function __construct(
		private readonly DaemonConfigMapper $daemonConfigMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'AppAPI 2.5.0 Daemons configuration update';
	}

	public function run(IOutput $output): void {
		$daemons = $this->daemonConfigMapper->findAll();
		$daemonsUpdated = 0;
		// Update manual-install daemons
		/** @var DaemonConfig $daemon */
		foreach ($daemons as $daemon) {
			$daemonsUpdated += $this->updateDaemonConfiguration($daemon);
		}
		$output->info(sprintf('Daemons configurations updated to V2.5.0: %s', $daemonsUpdated));
	}

	private function updateDaemonConfiguration(DaemonConfig $daemonConfig): int {
		$updated = false;
		if ($daemonConfig->getAcceptsDeployId() === 'manual-install') {
			if ($daemonConfig->getProtocol() == 0) {
				$daemonConfig->setProtocol('http');
				$updated = true;
			}
			if ($daemonConfig->getHost() == 0) {
				$daemonConfig->setHost('host.docker.internal');
				$updated = true;
			}
		}

		if ($daemonConfig->getAcceptsDeployId() === 'docker-install') {
			if ($daemonConfig->getProtocol() === 'unix-socket') {
				$daemonConfig->setProtocol('http');
				$updated = true;
			}
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
