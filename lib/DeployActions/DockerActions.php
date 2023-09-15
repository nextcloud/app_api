<?php

declare(strict_types=1);

namespace OCA\AppAPI\DeployActions;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Service\AppAPIService;

use OCP\App\IAppManager;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class DockerActions implements IDeployActions {
	public const DOCKER_API_VERSION = 'v1.41';
	public const AE_REQUIRED_ENVS = [
		'AA_VERSION',
		'APP_SECRET',
		'APP_ID',
		'APP_DISPLAY_NAME',
		'APP_VERSION',
		'APP_PROTOCOL',
		'APP_HOST',
		'APP_PORT',
		'APP_PERSISTENT_STORAGE',
		'IS_SYSTEM_APP',
		'NEXTCLOUD_URL',
	];
	public const EX_APP_CONTAINER_PREFIX = 'nc_app_';
	private LoggerInterface $logger;
	private Client $guzzleClient;
	private ICertificateManager $certificateManager;
	private IConfig $config;
	private IAppManager $appManager;
	private ISecureRandom $random;
	private IURLGenerator $urlGenerator;
	private AppAPIService $service;

	public function __construct(
		LoggerInterface     $logger,
		IConfig             $config,
		ICertificateManager $certificateManager,
		IAppManager         $appManager,
		ISecureRandom       $random,
		IURLGenerator       $urlGenerator,
		AppAPIService       $service,
	) {
		$this->logger = $logger;
		$this->certificateManager = $certificateManager;
		$this->config = $config;
		$this->appManager = $appManager;
		$this->random = $random;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
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

		$containerInfo = $this->inspectContainer($dockerUrl, $this->buildExAppContainerName($params['container_params']['name']));
		if (isset($containerInfo['Id'])) {
			[$stopResult, $removeResult] = $this->removePrevExAppContainer($dockerUrl, $this->buildExAppContainerName($params['container_params']['name']));
			if (isset($stopResult['error']) || isset($removeResult['error'])) {
				return [$pullResult, $stopResult, $removeResult];
			}
		}

		$createResult = $this->createContainer($dockerUrl, $imageParams, $containerParams);
		if (isset($createResult['error'])) {
			return [null, $createResult, null];
		}

		$startResult = $this->startContainer($dockerUrl, $this->buildExAppContainerName($params['container_params']['name']));
		return [$pullResult, $createResult, $startResult];
	}

	public function buildApiUrl(string $dockerUrl, string $route): string {
		return sprintf('%s/%s/%s', $dockerUrl, self::DOCKER_API_VERSION, $route);
	}

	public function buildImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' . $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	public function createContainer(string $dockerUrl, array $imageParams, array $params = []): array {
		$createVolumeResult = $this->createVolume($dockerUrl, $this->buildExAppVolumeName($params['name']));
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

		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/create?name=%s', urlencode($this->buildExAppContainerName($params['name']))));
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

	public function stopContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/stop', $containerId));
		try {
			$response = $this->guzzleClient->post($url);
			return ['success' => $response->getStatusCode() === 204];
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to stop container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to stop container'];
		}
	}

	public function removeContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s', $containerId));
		try {
			$response = $this->guzzleClient->delete($url);
			return ['success' => $response->getStatusCode() === 204];
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to stop container', ['exception' => $e]);
			error_log($e->getMessage());
			return ['error' => 'Failed to stop container'];
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
			return ['error' => $e->getMessage(), 'exception' => $e];
		}
	}

	public function createVolume(string $dockerUrl, string $volume): array {
		$url = $this->buildApiUrl($dockerUrl, 'volumes/create');
		try {
			$options['json'] = [
				'name' => $volume,
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

	public function removeVolume(string $dockerUrl, string $volume): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('volumes/%s', $volume));
		try {
			$options['json'] = [
				'name' => $volume,
			];
			$response = $this->guzzleClient->delete($url, $options);
			if ($response->getStatusCode() === 204) {
				return ['success' => true];
			}
			if ($response->getStatusCode() === 404) {
				error_log('Volume not found.');
				return ['error' => 'Volume not found.'];
			}
			if ($response->getStatusCode() === 409) {
				error_log('Volume is in use.');
				return ['error' => 'Volume is in use.'];
			}
			if ($response->getStatusCode() === 500) {
				error_log('Something went wrong.');
				return ['error' => 'Something went wrong.'];
			}
		} catch (GuzzleException $e) {
			$this->logger->error('Failed to create volume', ['exception' => $e]);
			error_log($e->getMessage());
		}
		return ['error' => 'Failed to remove volume'];
	}

	/**
	 * @param DaemonConfig $daemonConfig
	 * @param array $params Deploy params (image_params, container_params)
	 *
	 * @return array
	 */
	public function updateExApp(DaemonConfig $daemonConfig, array $params = []): array {
		$dockerUrl = $this->buildDockerUrl($daemonConfig);

		$pullResult = $this->pullContainer($dockerUrl, $params['image_params']);
		if (isset($pullResult['error'])) {
			return [$pullResult, null, null, null, null];
		}

		[$stopResult, $removeResult] = $this->removePrevExAppContainer($dockerUrl, $this->buildExAppContainerName($params['container_params']['name']));
		if (isset($stopResult['error'])) {
			return [$pullResult, $stopResult, null, null, null];
		}
		if (isset($removeResult['error'])) {
			return [$pullResult, $stopResult, $removeResult, null, null];
		}

		$createResult = $this->createContainer($dockerUrl, $params['image_params'], $params['container_params']);
		if (isset($createResult['error'])) {
			return [$pullResult, $stopResult, $removeResult, $createResult, null];
		}

		$startResult = $this->startContainer($dockerUrl, $this->buildExAppContainerName($params['container_params']['name']));
		return [$pullResult, $stopResult, $removeResult, $createResult, $startResult];
	}

	public function removePrevExAppContainer(string $dockerUrl, string $containerId): array {
		$stopResult = $this->stopContainer($dockerUrl, $containerId);
		if (isset($stopResult['error'])) {
			return [$stopResult, null];
		}

		$removeResult = $this->removeContainer($dockerUrl, $containerId);
		return [$stopResult, $removeResult];
	}

	public function buildDeployParams(DaemonConfig $daemonConfig, \SimpleXMLElement $infoXml, array $params = []): array {
		$appId = (string) $infoXml->id;
		$deployConfig = $daemonConfig->getDeployConfig();

		// If update process
		if (isset($params['container_info'])) {
			$containerInfo = $params['container_info'];
			$oldEnvs = $this->extractDeployEnvs((array) $containerInfo['Config']['Env']);
			$port = $oldEnvs['APP_PORT'] ?? $this->service->getExAppRandomPort();
			$secret = $oldEnvs['APP_SECRET'];
			$storage = $oldEnvs['APP_PERSISTENT_STORAGE'];
			// Preserve previous devices or use from params (if any)
			$devices = array_map(function (array $device) {
				return $device['PathOnHost'];
			}, (array) $containerInfo['HostConfig']['Devices']);
		} else {
			$port = $this->service->getExAppRandomPort();
			$devices = $deployConfig['gpus'];
			$storage = $this->buildDefaultExAppVolume($appId)[0]['Target'];
		}

		$imageParams = [
			'image_src' => (string) ($infoXml->xpath('ex-app/docker-install/registry')[0] ?? 'docker.io'),
			'image_name' => (string) ($infoXml->xpath('ex-app/docker-install/image')[0] ?? $appId),
			'image_tag' => (string) ($infoXml->xpath('ex-app/docker-install/image-tag')[0] ?? 'latest'),
		];

		$envs = $this->buildDeployEnvs([
			'appid' => $appId,
			'name' => (string) $infoXml->name,
			'version' => (string) $infoXml->version,
			'protocol' => (string) ($infoXml->xpath('ex-app/protocol')[0] ?? 'http'),
			'host' => $this->service->buildExAppHost($deployConfig),
			'port' => $port,
			'storage' => $storage,
			'system_app' => (bool) ($infoXml->xpath('ex-app/system')[0] ?? false),
			'secret' => $secret ?? $this->random->generate(128),
		], $params['env_options'] ?? [], $deployConfig);

		$containerParams = [
			'name' => $appId,
			'hostname' => $appId,
			'port' => $port,
			'net' => $deployConfig['net'] ?? 'host',
			'env' => $envs,
			'devices' => $devices,
		];

		return [
			'image_params' => $imageParams,
			'container_params' => $containerParams,
		];
	}

	private function extractDeployEnvs(array $envs): array {
		$deployEnvs = [];
		foreach ($envs as $env) {
			[$key, $value] = explode('=', $env, 2);
			if (in_array($key, DockerActions::AE_REQUIRED_ENVS, true)) {
				$deployEnvs[$key] = $value;
			}
		}
		return $deployEnvs;
	}

	public function buildDeployEnvs(array $params, array $envOptions, array $deployConfig): array {
		$autoEnvs = [
			sprintf('AA_VERSION=%s', $this->appManager->getAppVersion(Application::APP_ID, false)),
			sprintf('APP_SECRET=%s', $params['secret']),
			sprintf('APP_ID=%s', $params['appid']),
			sprintf('APP_DISPLAY_NAME=%s', $params['name']),
			sprintf('APP_VERSION=%s', $params['version']),
			sprintf('APP_PROTOCOL=%s', $params['protocol']),
			sprintf('APP_HOST=%s', $params['host']),
			sprintf('APP_PORT=%s', $params['port']),
			sprintf('APP_PERSISTENT_STORAGE=%s', $params['storage']),
			sprintf('IS_SYSTEM_APP=%s', $params['system_app']),
			sprintf('NEXTCLOUD_URL=%s', $deployConfig['nextcloud_url'] ?? str_replace('https', 'http', $this->urlGenerator->getAbsoluteURL(''))),
		];

		foreach ($envOptions as $envOption) {
			[$key, $value] = explode('=', $envOption, 2);
			// Do not overwrite required auto generated envs
			if (!in_array($key, DockerActions::AE_REQUIRED_ENVS, true)) {
				$autoEnvs[] = sprintf('%s=%s', $key, $value);
			}
		}

		return $autoEnvs;
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
		$containerInfo = $this->inspectContainer($this->buildDockerUrl($daemonConfig), $this->buildExAppContainerName($appId));
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
				'Source' => $this->buildExAppVolumeName($appId),
				'Target' => '/' . $this->buildExAppVolumeName($appId),
				'ReadOnly' => false
			],
		];
	}

	public function isDockerSocketAvailable(): bool {
		$dockerSocket = '/var/run/docker.sock';
		if (file_exists($dockerSocket) && is_readable($dockerSocket)) {
			return true;
		}
		return false;
	}

	/**
	 * Build ExApp container name (prefix + appid)
	 *
	 * @param string $appId
	 *
	 * @return string
	 */
	public function buildExAppContainerName(string $appId): string {
		return self::EX_APP_CONTAINER_PREFIX . $appId;
	}

	public function buildExAppVolumeName(string $appId): string {
		return self::EX_APP_CONTAINER_PREFIX . $appId . '_data';
	}
}
