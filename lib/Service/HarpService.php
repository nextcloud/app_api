<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\DeployActions\ManualActions;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class HarpService {
	private Client $guzzleClient;

	public function __construct(
		private readonly LoggerInterface     $logger,
		private readonly IConfig             $config,
		private readonly ICertificateManager $certificateManager,
		private readonly ICrypto             $crypto,
	) {
	}

	protected function setupCerts(array $guzzleParams): array {
		if (!$this->config->getSystemValueBool('installed', false)) {
			$certs = \OC::$SERVERROOT . '/resources/config/ca-bundle.crt';
		} else {
			$certs = $this->certificateManager->getAbsoluteBundlePath();
		}

		$guzzleParams['verify'] = $certs;
		return $guzzleParams;
	}

	/**
	 * @param DaemonConfig $daemonConfig
	 * @return string|null
	 * @throws \Exception
	 */
	public function getHarpSharedKey(array $deployConfig): ?string {
		try {
			return $this->crypto->decrypt($deployConfig['haproxy_password']);
		} catch (\Exception $e) {
			throw new \Exception('Failed to decrypt harp shared key', 0, $e);
		}
	}

	/**
	 * @param DaemonConfig $daemonConfig
	 * @return void
	 * @throws \Exception
	 */
	protected function initGuzzleClient(DaemonConfig $daemonConfig): void {
		$guzzleParams = [];
		if ($daemonConfig->getProtocol() === 'https') {
			$guzzleParams = $this->setupCerts($guzzleParams);
		}
		if (!isset($daemonConfig->getDeployConfig()['haproxy_password']) || $daemonConfig->getDeployConfig()['haproxy_password'] === '') {
			throw new \Exception('Harp shared key is not set');
		}

		$harpKey = $this->crypto->decrypt($daemonConfig->getDeployConfig()['haproxy_password']);
		if ($daemonConfig->getDeployConfig()['harp'] ?? false) {
			$guzzleParams['headers'] = [
				'harp-shared-key' => $harpKey,
			];
		}
		$this->guzzleClient = new Client($guzzleParams);
	}

	protected function buildHarpUrl(DaemonConfig $daemonConfig, string $path): string {
		return rtrim($daemonConfig->getProtocol() . '://' . $daemonConfig->getHost(), '/')
			. '/exapps/app_api/'
			. ltrim($path, '/');
	}

	public static function isHarp(array $deployConfig): bool {
		return boolval($deployConfig['harp'] ?? false);
	}

	public static function isHarpDirectConnect(array $deployConfig): bool {
		return isset($deployConfig['harp']['exapp_direct']) && $deployConfig['harp']['exapp_direct'] === true;
	}

	public static function getExAppHost(ExApp $exApp): string {
		$deployConfig = $exApp->getDeployConfig();
		if (HarpService::isHarpDirectConnect($deployConfig)) {
			if (isset($deployConfig['additional_options']['OVERRIDE_APP_HOST']) &&
				$deployConfig['additional_options']['OVERRIDE_APP_HOST'] !== ''
			) {
				$wideNetworkAddresses = ['0.0.0.0', '127.0.0.1', '::', '::1'];
				if (!in_array($deployConfig['additional_options']['OVERRIDE_APP_HOST'], $wideNetworkAddresses)) {
					return $deployConfig['additional_options']['OVERRIDE_APP_HOST'];
				}
			}
			if ($exApp->getAcceptsDeployId() !== ManualActions::DEPLOY_ID) {
				return $exApp->getAppid();
			}
		}
		return "127.0.0.1";
	}

	public function getHarpExApp(ExApp $exApp): array {
		return [
			'exapp_token' => $exApp->getSecret(),
			'exapp_version' => $exApp->getVersion(),
			'host' => $this->getExAppHost($exApp),
			'port' => $exApp->getPort(),
			'routes' => array_map(function ($route) {
				$bruteforceList = json_decode($route['bruteforce_protection'], true);
				if (!$bruteforceList) {
					$bruteforceList = [];
				}
				return [
					'url' => $route['url'],
					'access_level' => $route['access_level'],
					'bruteforce_protection' => $bruteforceList,
				];
			}, $exApp->getRoutes()),
		];
	}

	public function harpExAppUpdate(DaemonConfig $daemonConfig, ExApp $exApp, bool $added): void {
		if (!self::isHarp($daemonConfig->getDeployConfig())) {
			return;
		}
		$this->initGuzzleClient($daemonConfig);
		$appId = $exApp->getAppid();
		$url = $this->buildHarpUrl($daemonConfig, "/exapp_storage/$appId");
		$addedStr = $added ? 'add' : 'remove';
		$this->logger->info("HarpService: harpExAppUpdate ($addedStr): " . $url);

		try {
			if ($added) {
				$this->guzzleClient->post($url, [
					'json' => $this->getHarpExApp($exApp),
				]);
			} else {
				$this->guzzleClient->delete($url);
			}
		} catch (ClientException $e) {
			if (!$added && $e->getResponse()->getStatusCode() === 404) {
				$this->logger->info("HarpService: harpExAppUpdate ($addedStr) - 404 Not Found: " . $url);
			} else {
				$this->logger->error("HarpService: harpExAppUpdate ($addedStr) failed: " . $e->getMessage());
			}
		} catch (\Exception $e) {
			$this->logger->error("HarpService: harpExAppUpdate ($addedStr) failed: " . $e->getMessage());
		}
	}
}
