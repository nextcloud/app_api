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
		private readonly AIODockerActions     $AIODockerActions,
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

			// Register Docker Socket Proxy daemon if enabled
			if ($dspEnabled) {
				$output->info('Docker Socket Proxy is enabled in AIO. Registering DSP daemon');
				if ($this->AIODockerActions->registerAIODaemonConfig() !== null) {
					$output->info('AIO Docker Socket Proxy DaemonConfig successfully registered');
				}
			}

			// Register HaRP daemon if enabled (HaRP becomes default when both are enabled)
			if ($harpEnabled) {
				$output->info('HaRP is enabled in AIO. Registering HaRP daemon');
				if ($this->AIODockerActions->registerAIOHarpDaemonConfig() !== null) {
					$output->info('AIO HaRP DaemonConfig successfully registered');
				}
			}
		}
	}
}
