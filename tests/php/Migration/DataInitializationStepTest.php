<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Migration;

use OCA\AppAPI\DeployActions\AIODockerActions;
use OCA\AppAPI\Migration\DataInitializationStep;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class DataInitializationStepTest extends TestCase {
	public function testWarnsAboutTheDockerSocketProxyOfAio(): void {
		$aioDockerActions = $this->createMock(AIODockerActions::class);
		$aioDockerActions->method('isAIO')->willReturn(true);
		$aioDockerActions->method('isDockerSocketProxyEnabled')->willReturn(true);
		$aioDockerActions->method('isHarpEnabled')->willReturn(false);
		$aioDockerActions->expects(self::never())->method('registerAIOHarpDaemonConfig');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')
			->with('Docker Socket Proxy is enabled in AIO, but AppAPI no longer supports it. Use HaRP instead.');

		(new DataInitializationStep($aioDockerActions))->run($output);
	}
}
