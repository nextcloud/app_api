<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Fetcher;

use InvalidArgumentException;
use OC\App\AppStore\Version\VersionParser;
use OC\App\CompareVersion;
use OC\Files\AppData\Factory;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Server;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

class ExAppFetcher extends AppAPIFetcher {
	private bool $ignoreMaxVersion;

	public function __construct(
		Factory $appDataFactory,
		IClientService $clientService,
		ITimeFactory $timeFactory,
		IConfig $config,
		private CompareVersion $compareVersion,
		LoggerInterface $logger,
		protected IRegistry $registry,
	) {
		parent::__construct(
			$appDataFactory,
			$clientService,
			$timeFactory,
			$config,
			$logger,
			$registry,
		);

		$this->fileName = 'appapi_apps.json';
		$this->endpointName = 'appapi_apps.json';
		$this->ignoreMaxVersion = true;
	}

	/**
	 * Only returns the latest compatible app release in the releases array
	 */
	protected function fetch(string $ETag, string $content, bool $allowUnstable = false): array {
		/** @var mixed[] $response */
		$response = parent::fetch($ETag, $content);

		if (!isset($response['data']) || $response['data'] === null) {
			$this->logger->warning('Response from appstore is invalid, ExApps could not be retrieved. Try again later.', ['app' => 'appstoreExAppFetcher']);
			return [];
		}

		$allowPreReleases = $allowUnstable || $this->getChannel() === 'beta' || $this->getChannel() === 'daily' || $this->getChannel() === 'git';
		$allowNightly = $allowUnstable || $this->getChannel() === 'daily' || $this->getChannel() === 'git';

		foreach ($response['data'] as $dataKey => $app) {
			$releases = [];

			// Filter all compatible releases
			foreach ($app['releases'] as $release) {
				// Exclude all nightly and pre-releases if required
				if (($allowNightly || $release['isNightly'] === false)
					&& ($allowPreReleases || !str_contains($release['version'], '-'))) {
					// Exclude all versions not compatible with the current version
					try {
						$versionParser = new VersionParser();
						$serverVersion = $versionParser->getVersion($release['rawPlatformVersionSpec']);
						$ncVersion = $this->getVersion();
						$minServerVersion = $serverVersion->getMinimumVersion();
						$maxServerVersion = $serverVersion->getMaximumVersion();
						$minFulfilled = $this->compareVersion->isCompatible($ncVersion, $minServerVersion, '>=');
						$maxFulfilled = $maxServerVersion !== '' &&
							$this->compareVersion->isCompatible($ncVersion, $maxServerVersion, '<=');
						$isPhpCompatible = true;
						if (($release['rawPhpVersionSpec'] ?? '*') !== '*') {
							$phpVersion = $versionParser->getVersion($release['rawPhpVersionSpec']);
							$minPhpVersion = $phpVersion->getMinimumVersion();
							$maxPhpVersion = $phpVersion->getMaximumVersion();
							$minPhpFulfilled = $minPhpVersion === '' || $this->compareVersion->isCompatible(
								PHP_VERSION,
								$minPhpVersion,
								'>='
							);
							$maxPhpFulfilled = $maxPhpVersion === '' || $this->compareVersion->isCompatible(
								PHP_VERSION,
								$maxPhpVersion,
								'<='
							);

							$isPhpCompatible = $minPhpFulfilled && $maxPhpFulfilled;
						}
						if ($minFulfilled && ($this->ignoreMaxVersion || $maxFulfilled) && $isPhpCompatible) {
							$releases[] = $release;
						}
					} catch (InvalidArgumentException $e) {
						$this->logger->warning($e->getMessage(), [
							'exception' => $e,
						]);
					}
				}
			}

			if (empty($releases)) {
				// Remove apps that don't have a matching release
				$response['data'][$dataKey] = [];
				continue;
			}

			// Get the highest version
			$versions = [];
			foreach ($releases as $release) {
				$versions[] = $release['version'];
			}
			usort($versions, function ($version1, $version2) {
				return version_compare($version1, $version2);
			});
			$versions = array_reverse($versions);
			if (isset($versions[0])) {
				$highestVersion = $versions[0];
				foreach ($releases as $release) {
					if ((string)$release['version'] === (string)$highestVersion) {
						$response['data'][$dataKey]['releases'] = [$release];
						break;
					}
				}
			}
		}

		$response['data'] = array_values(array_filter($response['data']));
		return $response;
	}

	public function setVersion(string $version, string $fileName = 'appapi_apps.json', bool $ignoreMaxVersion = true): void {
		parent::setVersion($version);
		$this->fileName = $fileName;
		$this->ignoreMaxVersion = $ignoreMaxVersion;
	}

	public function get($allowUnstable = false): array {
		$allowPreReleases = $allowUnstable || $this->getChannel() === 'beta' || $this->getChannel() === 'daily' || $this->getChannel() === 'git';

		$apps = parent::get($allowPreReleases);
		if (empty($apps)) {
			return [];
		}
		$allowList = $this->config->getSystemValue('appsallowlist');

		// If the admin specified a allow list, filter apps from the appstore
		if (is_array($allowList) && $this->registry->delegateHasValidSubscription()) {
			return array_filter($apps, function ($app) use ($allowList) {
				return in_array($app['id'], $allowList);
			});
		}

		return $apps;
	}

	public function getExAppsWithUpdates(): array {
		$apps = $this->get();
		$appsWithUpdates = array_filter($apps, function (array $app) {
			$exAppService = Server::get(ExAppService::class);
			$exApp = $exAppService->getExApp($app['id']);
			$newestVersion = $app['releases'][0]['version'];
			return $exApp !== null && isset($app['releases'][0]['version']) && version_compare($newestVersion, $exApp->getVersion(), '>');
		});
		return array_values($appsWithUpdates);
	}
}
