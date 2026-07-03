<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Tracks which ExApps have opted in to setup checks, plus the last computed results.
 *
 * Opting in is just a per-ExApp marker in IAppConfig (keyed by the authenticated appid) - the live
 * results come from the ExApp's `/setup_checks` endpoint, fetched by the background refresh
 * ({@see ExAppSetupCheckRefreshService}) and stored under a single state key. No bespoke table.
 */
class ExAppSetupCheckService {
	public const KEY_PREFIX = 'setup_checks_';
	/** Caps the issues surfaced per ExApp in one refresh ({@see ExAppSetupCheckRefreshService}). */
	public const MAX_CHECKS = 16;
	/** IAppConfig enforces a 64-char key limit; KEY_PREFIX + appId must fit. */
	private const MAX_KEY_LENGTH = 64;
	/** Computed-results key. Deliberately does NOT start with KEY_PREFIX so getOptedInAppIds skips it. */
	private const STATE_KEY = 'setupchecks_state';

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	/**
	 * Opt an ExApp in to setup checks (it will then be probed by the background refresh).
	 *
	 * @param string $appId authenticated ExApp id (never a request param) - an ExApp can only ever
	 *                      opt itself in or out
	 */
	public function optIn(string $appId): void {
		if (!$this->isValidAppId($appId)) {
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_PREFIX . $appId, '1');
	}

	public function optOut(string $appId): void {
		if (!$this->isValidAppId($appId)) {
			return;
		}
		if ($this->appConfig->hasKey(Application::APP_ID, self::KEY_PREFIX . $appId)) {
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_PREFIX . $appId);
		}
		// Also drop the app's last computed results, so a re-opt-in (or reinstall of the same appid)
		// before the next refresh cannot momentarily show stale issues from the previous registration.
		$state = $this->getState();
		if (isset($state['apps'][$appId])) {
			unset($state['apps'][$appId]);
			$this->storeState($state['apps']);
		}
	}

	private function isValidAppId(string $appId): bool {
		return $appId !== '' && strlen(self::KEY_PREFIX . $appId) <= self::MAX_KEY_LENGTH;
	}

	/**
	 * App ids of every ExApp opted in to setup checks.
	 *
	 * Enabled-state is intentionally NOT filtered here - the caller filters live against the current
	 * ExApp state, so there is no cached "enabled" snapshot to go stale.
	 *
	 * @return list<string>
	 */
	public function getOptedInAppIds(): array {
		$appIds = [];
		foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
			if (!str_starts_with($key, self::KEY_PREFIX)) {
				continue;
			}
			$appId = substr($key, strlen(self::KEY_PREFIX));
			if ($appId !== '') {
				$appIds[] = $appId;
			}
		}
		return $appIds;
	}

	/**
	 * The last computed results, written by the background refresh and read (never recomputed) by the
	 * SetupCheck on the admin page.
	 *
	 * @return array{apps: array<string, list<array<string, mixed>>>, updatedAt: int}
	 */
	public function getState(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::STATE_KEY, ''), true);
		$apps = (is_array($decoded) && isset($decoded['apps']) && is_array($decoded['apps'])) ? $decoded['apps'] : [];
		$updatedAt = (is_array($decoded) && isset($decoded['updatedAt'])) ? (int)$decoded['updatedAt'] : 0;
		return ['apps' => $apps, 'updatedAt' => $updatedAt];
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $apps appId => list of issues
	 */
	public function storeState(array $apps): void {
		$this->appConfig->setValueString(Application::APP_ID, self::STATE_KEY, json_encode(['apps' => $apps, 'updatedAt' => time()]));
	}
}
