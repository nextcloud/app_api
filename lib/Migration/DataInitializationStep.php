<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Migration;

use OCA\AppEcosystemV2\DeployActions\DockerAIOActions;
use OCA\AppEcosystemV2\Service\ExAppApiScopeService;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class DataInitializationStep implements IRepairStep {
	private ExAppApiScopeService $service;
	private DockerAIOActions $dockerAIOActions;

	public function __construct(
		ExAppApiScopeService $service,
		DockerAIOActions $dockerAIOActions,
	) {
		$this->service = $service;
		$this->dockerAIOActions = $dockerAIOActions;
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
		// If in AIO - automatically register default DaemonConfig to work with master container API
		if ($this->dockerAIOActions->isAIO()) {
			$output->info('AIO installation detected. Registering default daemon');
			if ($this->dockerAIOActions->registerAIODaemonConfig() !== null) {
				$output->info('AIO DaemonConfig successfully registered');
			}
		}
	}
}
