<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Migration;

use OCA\AppEcosystemV2\Service\ExAppApiScopeService;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class DataInitializationStep implements IRepairStep {
	private ExAppApiScopeService $service;

	public function __construct(ExAppApiScopeService $service) {
		$this->service = $service;
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
	}
}
