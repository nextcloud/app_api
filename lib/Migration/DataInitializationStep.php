<?php

declare(strict_types=1);

namespace OCA\AppAPI\Migration;

use OCA\AppAPI\DeployActions\AIODockerActions;
use OCA\AppAPI\Service\ExAppApiScopeService;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class DataInitializationStep implements IRepairStep {
	private ExAppApiScopeService $service;
	private AIODockerActions $AIODockerActions;

	public function __construct(
		ExAppApiScopeService $service,
		AIODockerActions $AIODockerActions
	) {
		$this->service = $service;
		$this->AIODockerActions = $AIODockerActions;
	}

	public function getName(): string {
		return 'Initializing data for App Ecosystem V2';
	}

	public function run(IOutput $output): void {
		if ($this->service->registerInitScopes()) {
			$output->info('Successfully initialized data for App Ecosystem V2');
		} else {
			$output->warning('Failed to initialize data for App Ecosystem V2');
		}

		// If in AIO - automatically register default DaemonConfig
		if ($this->AIODockerActions->isAIO()) {
			$output->info('AIO installation detected. Registering default daemon');
			if ($this->AIODockerActions->registerAIODaemonConfig() !== null) {
				$output->info('AIO DaemonConfig successfully registered');
			}
		}
	}
}
