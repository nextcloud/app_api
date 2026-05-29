<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Service\ExAppConfigService;
use OCA\AppAPI\Service\ExAppPreferenceService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Validates the lazy-loading design for ExApp config/preferences:
 *  - everything AppAPI writes is stored lazy (kept out of the per-request eager cache),
 *  - lazy values round-trip from a cold cache (the real cross-request OCS read path),
 *  - eager values written outside AppAPI (e.g. `occ config:app:set`, or the server-native
 *    provisioning_api user-config endpoint which writes eager) are still readable through the
 *    lazy-agnostic read path, so nothing becomes invisible.
 */
#[Group('DB')]
class StorageLazyLoadingTest extends TestCase {

	private const APP = 'phpunit_lazy_app';
	private const USER = 'phpunit_lazy_user';

	private ExAppConfigService $configService;
	private ExAppPreferenceService $preferenceService;
	private IAppConfig $appConfig;
	private IUserConfig $userConfig;
	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = Server::get(ExAppConfigService::class);
		$this->preferenceService = Server::get(ExAppPreferenceService::class);
		$this->appConfig = Server::get(IAppConfig::class);
		$this->userConfig = Server::get(IUserConfig::class);
		$this->db = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	public function testConfigValuesAreStoredLazy(): void {
		$this->configService->setAppConfigValue(self::APP, 'k', 'v');
		self::assertTrue($this->appConfig->isLazy(self::APP, 'k'), 'AppAPI config must be stored lazy');
	}

	public function testLazyConfigIsNotInEagerCacheButReadableViaLazyLoad(): void {
		$this->configService->setAppConfigValue(self::APP, 'k', 'v');
		$this->appConfig->clearCache();
		// A lazy value must be absent from the eager (non-lazy) cache that loads on every request...
		self::assertSame('', $this->appConfig->getValueString(self::APP, 'k', '', lazy: false));
		// ...but present once the lazy bucket is loaded.
		self::assertSame('v', $this->appConfig->getValueString(self::APP, 'k', '', lazy: true));
	}

	public function testLazyConfigReadableFromColdCacheViaService(): void {
		$this->configService->setAppConfigValue(self::APP, 'k', 'v');
		$this->appConfig->clearCache(); // simulate a fresh request with nothing pre-loaded
		$rows = $this->configService->getAppConfigValues(self::APP, ['k']);
		self::assertSame('v', $this->valueOf($rows, 'k'));
	}

	public function testSensitiveLazyConfigRoundTripsFromColdCache(): void {
		$this->configService->setAppConfigValue(self::APP, 'secret', 's3cr3t', sensitive: 1);
		$this->appConfig->clearCache();
		self::assertTrue($this->appConfig->isLazy(self::APP, 'secret'));
		self::assertTrue($this->appConfig->isSensitive(self::APP, 'secret', null));
		$rows = $this->configService->getAppConfigValues(self::APP, ['secret']);
		self::assertSame('s3cr3t', $this->valueOf($rows, 'secret'));
	}

	public function testEagerExternalConfigIsStillReadable(): void {
		// Simulate a value created outside AppAPI (e.g. `occ config:app:set` without --lazy): eager.
		$this->appConfig->setValueString(self::APP, 'eager_key', 'eagerval', lazy: false);
		$this->appConfig->clearCache();
		$rows = $this->configService->getAppConfigValues(self::APP, ['eager_key']);
		self::assertSame('eagerval', $this->valueOf($rows, 'eager_key'), 'eager keys must not be invisible to the service');
	}

	public function testPreferenceValuesAreStoredLazy(): void {
		$this->preferenceService->setUserConfigValue(self::USER, self::APP, 'k', 'v');
		self::assertTrue($this->userConfig->isLazy(self::USER, self::APP, 'k'), 'AppAPI preferences must be stored lazy');
	}

	public function testEagerExternalPreferenceIsStillReadable(): void {
		// The server-native provisioning_api user-config endpoint writes preferences EAGER.
		// Our read path must surface them too, or an ExApp mixing both APIs would lose data.
		$this->userConfig->setValueString(self::USER, self::APP, 'srv_pref', 'srvval', lazy: false);
		$this->userConfig->clearCache(self::USER);
		$rows = $this->preferenceService->getUserConfigValues(self::USER, self::APP, ['srv_pref']);
		self::assertSame('srvval', $this->valueOf($rows, 'srv_pref'), 'eager (server-native) prefs must be readable');
	}

	private function valueOf(?array $rows, string $key): ?string {
		foreach ($rows ?? [] as $row) {
			if ($row['configkey'] === $key) {
				return $row['configvalue'];
			}
		}
		return null;
	}

	private function cleanup(): void {
		// This test only writes through the services / IAppConfig / IUserConfig, i.e. into the
		// standard oc_appconfig / oc_preferences tables. It must NOT touch the legacy appconfig_ex /
		// preferences_ex tables, which the drop migration removes (they're absent in a fresh CI DB).
		foreach (['appconfig', 'preferences'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('appid', $qb->createNamedParameter(self::APP)));
			$qb->executeStatement();
		}
		$this->appConfig->clearCache();
		$this->userConfig->clearCacheAll();
	}
}
