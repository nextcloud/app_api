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

namespace OCA\AppEcosystemV2\Docker;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCP\ICertificateManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class DockerActions {
	public const DOCKER_API_VERSION = 'v1.41';
	private LoggerInterface $logger;
	private Client $guzzleClient;
	private ICertificateManager $certificateManager;
	private IConfig $config;

	public function __construct(
		LoggerInterface $logger,
		IConfig $config,
		ICertificateManager $certificateManager
	) {
		$this->logger = $logger;
		$this->certificateManager = $certificateManager;
		$this->config = $config;
	}

	/**
	 * Pull image, create and start container
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $imageParams
	 * @param array $containerParams
	 * @param array $sslParams
	 *
	 * @return array
	 */
	public function deployExApp(
		DaemonConfig $daemonConfig,
		array $imageParams,
		array $containerParams,
		array $sslParams,
	): array {
		if ($daemonConfig->getAcceptsDeployId() !== 'docker-install') {
			throw new \Exception('Only docker-install is supported for now.');
		}
		$dockerUrl = 'http://localhost';
		$guzzleParams = [];
		if ($daemonConfig->getProtocol() === 'unix-socket') {
			$guzzleParams = [
				'curl' => [
					CURLOPT_UNIX_SOCKET_PATH => $daemonConfig->getHost(),
				],
			];
		} else if (in_array($daemonConfig->getProtocol(), ['http', 'https'])) {
			$dockerUrl = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
			$guzzleParams = $this->setupCerts($guzzleParams, $sslParams);
		}
		$this->guzzleClient = new Client($guzzleParams);

		$pullResult = $this->pullContainer($dockerUrl, $imageParams);
		if (isset($pullResult['error'])) {
			return [$pullResult, null, null];
		}

		$createResult = $this->createContainer($dockerUrl, $imageParams, $containerParams);
		if (isset($createResult['error'])) {
			return [null, $createResult, null];
		}

		$startResult = $this->startContainer($dockerUrl, $createResult['Id']);
		return [$pullResult, $createResult, $startResult];
	}

	public function buildApiUrl(string $dockerUrl, string $route): string {
		return sprintf('%s/%s/%s', $dockerUrl, self::DOCKER_API_VERSION, $route);
	}

	public function buildImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' . $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	public function createContainer(string $dockerUrl, array $imageParams, array $params = []): array {
		$containerParams = [
			'Image' => $this->buildImageName($imageParams),
			'Hostname' => $params['hostname'],
			'HostConfig' => [
				'NetworkMode' => $params['net'],
			],
			'Env' => $params['env'],
		];

		if (!in_array($params['net'], ['host', 'bridge'])) {
			$networkingConfig = [
				'EndpointsConfig' => [
					$params['net'] => [
						'Aliases' => [
							$params['hostname']
						],
					],
				],
			];
			$containerParams['NetworkingConfig'] = $networkingConfig;
		}

		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/create?name=%s', urlencode($params['name'])));
		try {
			$options['json'] = $containerParams;
			$response = $this->guzzleClient->post($url, $options);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to create container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to create container'];
		}
	}

	public function startContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/start', $containerId));
		try {
			$response = $this->guzzleClient->post($url);
			return ['success' => $response->getStatusCode() === 204];
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to start container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to start container'];
		}
	}

	public function pullContainer(string $dockerUrl, array $params): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('images/create?fromImage=%s', $this->buildImageName($params)));
		try {
			$xRegistryAuth = json_encode([
				'https://' . $params['image_src'] => []
			], JSON_UNESCAPED_SLASHES);
			$response = $this->guzzleClient->post($url, [
				'headers' => [
					'X-Registry-Auth' => base64_encode($xRegistryAuth),
				],
			]);
			return ['success' => $response->getStatusCode() === 200];
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to pull image', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to pull image.'];
		}
	}

	public function inspectContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/json', $containerId));
		try {
			$response = $this->guzzleClient->get($url);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to inspect container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to inspect container'];
		}
	}

	/**
	 * @param array $guzzleParams
	 * @param array $sslParams ['ssl_key', 'ssl_password', 'ssl_cert', 'ssl_cert_password']
	 *
	 * @return array
	 */
	private function setupCerts(array $guzzleParams, array $sslParams): array {
		if (!$this->config->getSystemValueBool('installed', false)) {
			$certs =  \OC::$SERVERROOT . '/resources/config/ca-bundle.crt';
		} else {
			$certs = $this->certificateManager->getAbsoluteBundlePath();
		}

		$guzzleParams['verify'] = $certs;
		if (isset($sslParams['ssl_key'])) {
			$guzzleParams['ssl_key'] = !isset($sslParams['ssl_key_password'])
				? $sslParams['ssl_key']
				: [$sslParams['ssl_key'], $sslParams['ssl_key_password']];
		}
		if (isset($sslParams['ssl_cert'])) {
			$guzzleParams['cert'] = !isset($sslParams['ssl_cert_password'])
				? $sslParams['ssl_cert']
				: [$sslParams['ssl_cert'], $sslParams['ssl_cert_password']];
		}
		return $guzzleParams;
	}
}
