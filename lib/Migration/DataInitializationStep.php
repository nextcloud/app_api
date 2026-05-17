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

			$harpEnabled = $this->AIODockerActions->isHarpEnabled();
			$dspEnabled = $this->AIODockerActions->isDockerSocketProxyEnabled();

			if ($harpEnabled) {
				$output->info('HaRP is enabled in AIO. Registering HaRP daemon');
				if ($this->AIODockerActions->registerAIOHarpDaemonConfig() !== null) {
					$output->info('AIO HaRP DaemonConfig successfully registered');
				}
			} elseif ($dspEnabled) {
				$output->warning('Docker Socket Proxy is enabled in AIO, but registration of new DSP daemons is no longer supported. Please enable HARP_ENABLED=yes in your AIO configuration so AppAPI can register a HaRP daemon.');
			}
		}
	}
}
