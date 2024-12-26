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
		// If in AIO - automatically register default DaemonConfig
		if ($this->AIODockerActions->isAIO()) {
			$output->info('AIO installation detected. Registering default daemon');
			if ($this->AIODockerActions->registerAIODaemonConfig() !== null) {
				$output->info('AIO DaemonConfig successfully registered');
			}
		}
	}
}
