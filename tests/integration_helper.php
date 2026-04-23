<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * CLI helper for AppAPI integration tests.
 *
 * Seeds or clears rows in `ex_deploy_options` directly, without running a full
 * `app_api:app:register` cycle. Used to reproduce multi-app states that are
 * awkward to set up via OCC alone (e.g. issue #808).
 *
 * Usage:
 *   php integration_helper.php set-env <appid> <name> <value>
 *   php integration_helper.php remove  <appid>
 */

require __DIR__ . '/../../../lib/base.php';

OCP\Server::get(OCP\App\IAppManager::class)->loadApp('app_api');
$service = OCP\Server::get(OCA\AppAPI\Service\ExAppDeployOptionsService::class);

$command = $argv[1] ?? '';
$appid = $argv[2] ?? '';

if ($appid === '') {
	fwrite(STDERR, "usage: integration_helper.php set-env <appid> <name> <value>\n");
	fwrite(STDERR, "       integration_helper.php remove <appid>\n");
	exit(1);
}

switch ($command) {
	case 'set-env':
		$name = $argv[3] ?? '';
		$value = $argv[4] ?? '';
		if ($name === '') {
			fwrite(STDERR, "missing <name>\n");
			exit(1);
		}
		$existing = $service->getDeployOption($appid, 'environment_variables');
		$envVars = $existing !== null ? $existing->getValue() : [];
		$envVars[$name] = ['name' => $name, 'value' => $value];
		if ($service->addExAppDeployOption($appid, 'environment_variables', $envVars) === null) {
			fwrite(STDERR, "failed to persist {$name}={$value} for {$appid}\n");
			exit(1);
		}
		echo "set {$name}={$value} for {$appid}\n";
		break;

	case 'remove':
		if ($service->removeExAppDeployOptions($appid) < 0) {
			fwrite(STDERR, "failed to remove deploy options for {$appid}\n");
			exit(1);
		}
		echo "removed deploy options for {$appid}\n";
		break;

	default:
		fwrite(STDERR, "unknown command '{$command}'\n");
		exit(1);
}
