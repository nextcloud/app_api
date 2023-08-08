<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\DeployActions;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

use OCA\AppEcosystemV2\Db\DaemonConfig;

use OCP\ICertificateManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class DockerActions implements IDeployActions {
	public const DOCKER_API_VERSION = 'v1.41';
	public const AE_REQUIRED_ENVS = [
		'AE_VERSION',
		'APP_SECRET',
		'APP_ID',
		'APP_DISPLAY_NAME',
		'APP_VERSION',
		'APP_PROTOCOL',
		'APP_HOST',
		'APP_PORT',
		'IS_SYSTEM_APP',
		'NEXTCLOUD_URL',
	];
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

	public function getAcceptsDeployId(): string {
		return 'docker-install';
	}

	/**
	 * Pull image, create and start container
	 *
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array
	 */
	public function deployExApp(DaemonConfig $daemonConfig, array $params = []): array {
		if ($daemonConfig->getAcceptsDeployId() !== 'docker-install') {
			return [['error' => 'Only docker-install is supported for now.'], null, null];
		}

		if (isset($params['image_params'])) {
			$imageParams = $params['image_params'];
		} else {
			return [['error' => 'Missing image_params.'], null, null];
		}

		if (isset($params['container_params'])) {
			$containerParams = $params['container_params'];
		} else {
			return [['error' => 'Missing container_params.'], null, null];
		}

		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);

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
		$createVolumeResult = $this->createVolume($dockerUrl, $params['hostname']);
		if (isset($createVolumeResult['error'])) {
			return $createVolumeResult;
		}

		$containerParams = [
			'Image' => $this->buildImageName($imageParams),
			'Hostname' => $params['hostname'],
			'HostConfig' => [
				'NetworkMode' => $params['net'],
				'Mounts' => $this->buildDefaultExAppVolume($params['hostname']),
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

		if (isset($params['devices'])) {
			$containerParams['HostConfig']['Devices'] = $this->buildDevicesParams($params['devices']);
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

	public function createVolume(string $dockerUrl, string $volume): array {
		$url = $this->buildApiUrl($dockerUrl, 'volumes/create');
		try {
			$options['json'] = [
				'name' => $volume . '_data',
			];
			$response = $this->guzzleClient->post($url, $options);
			$result = json_decode((string) $response->getBody(), true);
			if ($response->getStatusCode() === 201) {
				return $result;
			}
			if ($response->getStatusCode() === 500) {
				error_log($result['message']);
				return ['error' => $result['message']];
			}
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to create volume', ['exception' => $e]);
			error_log($e->getMessage());
		}
		return ['error' => 'Failed to create volume'];
	}

	/**
	 * @param string $appId
	 * @param DaemonConfig $daemonConfig
	 * @param array $params
	 *
	 * @return array
	 */
	public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array {
		$this->initGuzzleClient($daemonConfig);
		$containerInfo = $this->inspectContainer($this->buildDockerUrl($daemonConfig), $appId);
		if (isset($containerInfo['error'])) {
			return ['error' => sprintf('Failed to inspect ExApp %s container: %s', $appId, $containerInfo['error'])];
		}

		$containerEnvs = (array) $containerInfo['Config']['Env'];
		$aeEnvs = [];
		foreach ($containerEnvs as $env) {
			$envParts = explode('=', $env, 2);
			if (in_array($envParts[0], self::AE_REQUIRED_ENVS)) {
				$aeEnvs[$envParts[0]] = $envParts[1];
			}
		}

		if ($appId !== $aeEnvs['APP_ID']) {
			return ['error' => sprintf('ExApp appid %s does not match to deployed APP_ID %s.', $appId, $aeEnvs['APP_ID'])];
		}

		return [
			'appid' => $aeEnvs['APP_ID'],
			'name' => $aeEnvs['APP_DISPLAY_NAME'],
			'version' => $aeEnvs['APP_VERSION'],
			'secret' => $aeEnvs['APP_SECRET'],
			'host' => $this->resolveDeployExAppHost($appId, $daemonConfig),
			'port' => $aeEnvs['APP_PORT'],
			'protocol' => $aeEnvs['APP_PROTOCOL'],
			'system_app' => $aeEnvs['IS_SYSTEM_APP'] ?? false,
		];
	}

	public function resolveDeployExAppHost(string $appId, DaemonConfig $daemonConfig, array $params = []): string {
		$deployConfig = $daemonConfig->getDeployConfig();
		if (isset($deployConfig['net']) && $deployConfig['net'] === 'host') {
			$host = $deployConfig['host'] ?? 'localhost';
		} else {
			$host = $appId;
		}
		return $host;
	}

	public function containerStateHealthy(array $containerInfo): bool {
		return $containerInfo['State']['Status'] === 'running';
	}

	public function healthcheckContainer(string $containerId, DaemonConfig $daemonConfig): bool {
		$attempts = 0;
		$totalAttempts = 60; // ~60 seconds for container to initialize
		while ($attempts < $totalAttempts) {
			$containerInfo = $this->inspectContainer($this->buildDockerUrl($daemonConfig), $containerId);
			if ($this->containerStateHealthy($containerInfo)) {
				return true;
			}
			$attempts++;
			sleep(1);
		}
		return false;
	}

	public function buildDockerUrl(DaemonConfig $daemonConfig): string {
		$dockerUrl = 'http://localhost';
		if (in_array($daemonConfig->getProtocol(), ['http', 'https'])) {
			$dockerUrl = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		}
		return $dockerUrl;
	}

	public function initGuzzleClient(DaemonConfig $daemonConfig): void {
		$guzzleParams = [];
		if ($daemonConfig->getProtocol() === 'unix-socket') {
			$guzzleParams = [
				'curl' => [
					CURLOPT_UNIX_SOCKET_PATH => $daemonConfig->getHost(),
				],
			];
		} elseif (in_array($daemonConfig->getProtocol(), ['http', 'https'])) {
			$guzzleParams = $this->setupCerts($guzzleParams, $daemonConfig->getDeployConfig());
		}
		$this->guzzleClient = new Client($guzzleParams);
	}

	/**
	 * @param array $guzzleParams
	 * @param array $deployConfig
	 *
	 * @return array
	 */
	private function setupCerts(array $guzzleParams, array $deployConfig): array {
		if (!$this->config->getSystemValueBool('installed', false)) {
			$certs = \OC::$SERVERROOT . '/resources/config/ca-bundle.crt';
		} else {
			$certs = $this->certificateManager->getAbsoluteBundlePath();
		}

		$guzzleParams['verify'] = $certs;
		if (isset($deployConfig['ssl_key'])) {
			$guzzleParams['ssl_key'] = !isset($deployConfig['ssl_key_password'])
				? $deployConfig['ssl_key']
				: [$deployConfig['ssl_key'], $deployConfig['ssl_key_password']];
		}
		if (isset($deployConfig['ssl_cert'])) {
			$guzzleParams['cert'] = !isset($deployConfig['ssl_cert_password'])
				? $deployConfig['ssl_cert']
				: [$deployConfig['ssl_cert'], $deployConfig['ssl_cert_password']];
		}
		return $guzzleParams;
	}

	private function buildDevicesParams(array $devices): array {
		return array_map(function (string $device) {
			return ["PathOnHost" => $device, "PathInContainer" => $device, "CgroupPermissions" => "rwm"];
		}, $devices);
	}

	/**
	 * Build default volume for ExApp.
	 * For now only one volume created per ExApp.
	 *
	 * @param string $appId
	 * @return array
	 */
	private function buildDefaultExAppVolume(string $appId): array {
		return [
			[
				'Type' => 'volume',
				'Source' => $appId . '_data',
				'Target' => '/' . $appId . '_data',
				'ReadOnly' => false
			],
		];
	}
}
