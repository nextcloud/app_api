<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppMapper;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * Helper for controller tests that need a real row in `ex_apps` (e.g. so `findAllEnabled()`-style joins return data,
 * or so a service helper can call `getExApp($appId)` and get a proper Entity back).
 *
 * Each test class picks its own ($appId, $port) tuple — `daemon_config_name+port` has a UNIQUE index, so two parallel
 * test classes must not pick the same port.
 */
trait RegistersFakeExAppTrait {
	private function insertFakeExApp(string $appId, int $port, int $enabled = 1): void {
		$mapper = Server::get(ExAppMapper::class);

		// Best-effort cleanup of stale row from a previous failed run.
		try {
			$existing = $mapper->findByAppId($appId);
			$mapper->delete($existing);
		} catch (DoesNotExistException) {
		}

		// Only set columns that actually exist in the ex_apps table — protocol, host, last_check_time, deploy_config,
		// accepts_deploy_id, routes were dropped in Version2000Date20240120094952 and now live in ex_apps_daemons.
		$entity = new ExApp();
		$entity->setAppid($appId);
		$entity->setVersion('1.0.0');
		$entity->setName('PHPUnit fake ExApp');
		$entity->setDaemonConfigName('manual_install');
		$entity->setPort($port);
		$entity->setSecret(str_repeat('a', 64));
		$entity->setStatus([
			'progress' => 100, 'error' => '', 'type' => '',
			'action' => '', 'deploy' => 100, 'init' => 100,
		]);
		$entity->setEnabled($enabled);
		$entity->setCreatedTime(time());
		$mapper->insert($entity);
		$this->invalidateExAppCache();
	}

	private function deleteFakeExApp(string $appId): void {
		$mapper = Server::get(ExAppMapper::class);
		try {
			$mapper->delete($mapper->findByAppId($appId));
			$this->invalidateExAppCache();
		} catch (DoesNotExistException) {
		}
	}

	/**
	 * ExAppService caches getExApps() under '/ex_apps' on its own private $cache field. createDistributed() returns a
	 * fresh wrapper, so we reach into the singleton's actual property to invalidate the same instance it reads from.
	 */
	private function invalidateExAppCache(): void {
		$service = Server::get(ExAppService::class);
		$prop = new \ReflectionProperty($service, 'cache');
		$cache = $prop->getValue($service);
		if ($cache !== null) {
			$cache->remove('/ex_apps');
		}
	}
}
