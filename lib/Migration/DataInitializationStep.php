<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use OCA\AppAPI\DeployActions\AIODockerActions;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class DataInitializationStep implements IRepairStep {
	public function __construct(
		private readonly AIODockerActions $AIODockerActions,
	) {
	}

	public function getName(): string {
		return 'Initializing data for AppAPI';
	}

	public function run(IOutput $output): void {
		// If in AIO - automatically register default DaemonConfig(s)
		if ($this->AIODockerActions->isAIO()) {
			$output->info('AIO installation detected. Registering daemon(s)');

			if ($this->AIODockerActions->isDockerSocketProxyEnabled()) {
				$output->warning('Docker Socket Proxy is enabled in AIO, but AppAPI no longer supports it. Use HaRP instead.');
			}

			if ($this->AIODockerActions->isHarpEnabled()) {
				$output->info('HaRP is enabled in AIO. Registering HaRP daemon');
				if ($this->AIODockerActions->registerAIOHarpDaemonConfig() !== null) {
					$output->info('AIO HaRP DaemonConfig successfully registered');
				}
			}
		}
	}
}
