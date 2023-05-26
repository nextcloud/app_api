<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Migration;

use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

class AppDataInitializationStep implements IRepairStep {
	/** @var IAppManager */
	private $appManager;

	/** @var AppEcosystemV2Service */
	private $service;

	public function __construct(
		IAppManager $appManager,
		AppEcosystemV2Service $appEcosystemV2Service,
	) {
		$this->appManager = $appManager;
		$this->service = $appEcosystemV2Service;
	}

	public function getName(): string {
		return "Init App Ecosystem V2";
	}

	public function run(IOutput $output) {
		$output->startProgress(1);
		// TODO: Detect default external app, verify that it is enabled, verify connection
		// and update app info
		$this->service->detectDefaultExApp();
		$output->finishProgress();
	}
}
