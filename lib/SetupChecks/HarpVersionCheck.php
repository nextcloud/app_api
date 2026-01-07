<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\SetupChecks;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\HarpService;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Log\LoggerInterface;

class HarpVersionCheck implements ISetupCheck {
	private \OCP\ICache $cache;

	public function __construct(
		private readonly IL10N               $l10n,
		private readonly LoggerInterface     $logger,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly HarpService         $harpService,
		ICacheFactory                        $cacheFactory,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/harp_version_check');
		}
	}

	public function getName(): string {
		return $this->l10n->t('AppAPI HaRP version check');
	}

	public function getCategory(): string {
		return 'system';
	}

	/**
	 * @return DaemonConfig[]
	 */
	public function getHaRPDaemonConfigs(): array {
		$allDaemons = $this->daemonConfigService->getRegisteredDaemonConfigs();
		return array_filter($allDaemons, function (DaemonConfig $daemon) {
			return HarpService::isHarp($daemon->getDeployConfig());
		});
	}

	public function run(): SetupResult {
		$harpDaemons = $this->getHaRPDaemonConfigs();

		if (empty($harpDaemons)) {
			return SetupResult::success();
		}

		$issues = [];
		foreach ($harpDaemons as $daemonConfig) {
			try {
				$versionString = $this->getHarpVersion($daemonConfig);
				if ($versionString === null) {
					$issues[] = $this->l10n->t('Could not retrieve HaRP version from daemon "%s"', [$daemonConfig->getName()]);
					continue;
				}
				if (!$this->fulfillsMinimumVersionRequirement($versionString)) {
					$issues[] = $this->l10n->t('HaRP version for daemon "%s" is "%s", which is too old. The minimum required version is "%s". Please update the daemon to the latest version.', [$daemonConfig->getName(), $versionString, Application::MINIMUM_HARP_VERSION]);
				}
			} catch (\Exception $e) {
				$this->logger->error('Failed to check HaRP version for daemon ' . $daemonConfig->getName() . ': ' . $e->getMessage(), ['exception' => $e]);
				$issues[] = $this->l10n->t('Failed to check HaRP version for daemon "%s": %s', [$daemonConfig->getName(), $e->getMessage()]);
			}
		}

		if (!empty($issues)) {
			return SetupResult::warning(
				implode('\n', $issues),
				'https://github.com/nextcloud/HaRP/',
			);
		}

		return SetupResult::success();
	}

	private function fulfillsMinimumVersionRequirement(string $version): bool {
		return version_compare($version, Application::MINIMUM_HARP_VERSION, '>=');
	}

	private function getHarpVersion(DaemonConfig $daemonConfig): ?string {
		$cacheKey = $daemonConfig->getName() . '_' . (string)crc32(json_encode($daemonConfig));
		$version = $this->cache->get($cacheKey);
		if ($version === null) {
			$version = $this->harpService->getHarpVersion($daemonConfig);
			$oneWeek = 60 * 60 * 24 * 7;
			$this->cache->set($cacheKey, $version, $oneWeek);
		}
		return $version;
	}
}
