<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\DeployActions;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\DaemonConfig;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\AppEcosystemV2\Service\DaemonConfigService;
use OCP\App\IAppManager;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Nextcloud Docker AIO deploy actions.
 * Basically similar to default DockerActions with only difference in communication structure.
 *
 * Only for automatic AIO install with access to Docker socket (/var/run/docker.sock)
 */
class DockerAIOActions implements IDockerActions {
	// default master container hostname within Docker namespace and fixed port
	const MASTER_CONTAINER_HOST = 'https://nextcloud-aio-mastercontainer:8080';
	const AIO_DAEMON_CONFIG_NAME = 'docker_aio';
	const AIO_DOCKER_API = 'api/docker/app_ecosystem_v2';
	const AIO_NEXTCLOUD_CONTAINER = 'nextcloud-aio-nextcloud';
	private IConfig $config;
	private DaemonConfigService $daemonConfigService;
	private IURLGenerator $urlGenerator;
	private ICertificateManager $certificateManager;
	private Client $guzzleClient;
	private ISecureRandom $random;
	private IAppManager $appManager;
	private AppEcosystemV2Service $service;

	public function __construct(
		IConfig $config,
		DaemonConfigService $daemonConfigService,
		IURLGenerator $urlGenerator,
		ICertificateManager $certificateManager,
		ISecureRandom $random,
		IAppManager $appManager,
		AppEcosystemV2Service $service,
	) {
		$this->config = $config;
		$this->daemonConfigService = $daemonConfigService;
		$this->urlGenerator = $urlGenerator;
		$this->certificateManager = $certificateManager;
		$this->random = $random;
		$this->appManager = $appManager;
		$this->service = $service;
	}

	public function getAcceptsDeployId(): string {
		return 'aio-docker-install';
	}

	public function deployExApp(DaemonConfig $daemonConfig, array $params = []): array {
		if ($daemonConfig->getAcceptsDeployId() !== $this->getAcceptsDeployId()) {
			return ['error' => sprintf('Wrong accepts-deploy-id (%s)', $daemonConfig->getAcceptsDeployId()), null, null];
		}

		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);

		$createResult = $this->createContainer($dockerUrl, $params['image_params'], $params['container_params']);
		return [$createResult['pull'], $createResult['create'], $createResult['start']];
	}

	public function updateExApp(DaemonConfig $daemonConfig, array $params = []): array {
		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/update', $params['container_params']['name']));
		try {
			$deployParams = [
				'container_params' => $params['container_params'],
				'image_params' => $params['image_params'],
			];
			$options['json'] = [
				'container' => $this->convertToAIOContainerJson($deployParams)
			];
			$response = $this->guzzleClient->post($url, $options);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			return ['error' => $e->getMessage(), 'exception' => $e];
		}
	}

	public function buildDeployParams(DaemonConfig $daemonConfig, \SimpleXMLElement $infoXml, array $params = []): array {
		// TODO: Extract to common helper service for DockerActions
		$appId = (string) $infoXml->id;
		$deployConfig = $daemonConfig->getDeployConfig();

		// If update process
		if (isset($params['container_info'])) {
			$containerInfo = $params['container_info'];
			$oldEnvs = $this->extractDeployEnvs((array) $containerInfo['Config']['Env']);
			$port = $oldEnvs['APP_PORT'] ?? $this->service->getExAppRandomPort();
			$secret = $oldEnvs['APP_SECRET'];
			// Preserve previous devices or use from params (if any)
			$devices = array_map(function (array $device) {
				return $device['PathOnHost'];
			}, (array) $containerInfo['HostConfig']['Devices']);
		} else {
			$port = $this->service->getExAppRandomPort();
			$devices = $deployConfig['gpus'];
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

	public function buildDeployEnvs(array $params, array $envOptions, array $deployConfig): array {
		// TODO: Extract to common helper service for DockerActions
		$autoEnvs = [
			sprintf('AE_VERSION=%s', $this->appManager->getAppVersion(Application::APP_ID, false)),
			sprintf('APP_SECRET=%s', $params['secret']),
			sprintf('APP_ID=%s', $params['appid']),
			sprintf('APP_DISPLAY_NAME=%s', $params['name']),
			sprintf('APP_VERSION=%s', $params['version']),
			sprintf('APP_PROTOCOL=%s', $params['protocol']),
			sprintf('APP_HOST=%s', $params['host']),
			sprintf('APP_PORT=%s', $params['port']),
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

	public function loadExAppInfo(string $appId, DaemonConfig $daemonConfig, array $params = []): array {
		// TODO: Extract to common helper service for DockerActions
		$this->initGuzzleClient($daemonConfig);
		$containerInfo = $this->inspectContainer($this->buildDockerUrl($daemonConfig), $appId);
		if (isset($containerInfo['error'])) {
			return ['error' => sprintf('Failed to inspect ExApp %s container: %s', $appId, $containerInfo['error'])];
		}

		$containerEnvs = (array) $containerInfo['Config']['Env'];
		$aeEnvs = [];
		foreach ($containerEnvs as $env) {
			$envParts = explode('=', $env, 2);
			if (in_array($envParts[0], DockerActions::AE_REQUIRED_ENVS)) {
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

	public function createContainer(string $dockerUrl, array $imageParams, array $containerParams): array {
		$url = $this->buildApiUrl($dockerUrl, 'containers');
		try {
			$deployParams = [
				'container_params' => $containerParams,
				'image_params' => $imageParams,
				'volumes' => [$this->buildDefaultExAppVolume($containerParams['name'])],
			];
			$options['json'] = [
				'container' => $this->convertToAIOContainerJson($deployParams)
			];
			$response = $this->guzzleClient->post($url, $options);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			return ['error' => $e->getMessage(), 'exception' => $e];
		}
	}

	public function inspectContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s', $containerId));
		try {
			$response = $this->guzzleClient->get($url);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			return ['error' => $e->getMessage(), 'exception' => $e];
		}
	}

	public function removePrevExAppContainer(string $dockerUrl, string $containerId): array {
		$url = $this->buildApiUrl($dockerUrl, 'containers/');
		try {
			$response = $this->guzzleClient->get($url);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			return ['error' => $e->getMessage(), 'exception' => $e];
		}
	}

	public function removeVolume(string $dockerUrl, string $volume): array {
		$url = $this->buildApiUrl($dockerUrl, 'volumes');
		try {
			$options['json'] = [
				'volume' => $volume,
			];
			$response = $this->guzzleClient->delete($url, $options);
			return json_decode((string) $response->getBody(), true);
		} catch (GuzzleException $e) {
			return ['error' => $e->getMessage(), 'exception' => $e];
		}
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

	/**
	 * Registers DaemonConfig with default params to use Nextcloud AIO master container
	 * for Docker actions
	 *
	 * @return DaemonConfig|null
	 */
	public function registerAIODaemonConfig(): ?DaemonConfig {
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName(self::AIO_DAEMON_CONFIG_NAME);
		if ($daemonConfig !== null) {
			return null;
		}

		$deployConfig = [
			'net' => 'nextcloud-aio', // using the same host as default network for Nextcloud AIO containers
			'host' => null,
			'nextcloud_url' => $this->getNextcloudUrl(),
			'ssl_key' => null,
			'ssl_key_password' => null,
			'ssl_cert' => null,
			'ssl_cert_password' => null,
			'gpus' => [], // should be updated on AIO master container API side depending on NEXTCLOUD_ENABLE_DRI_DEVICE env
		];

		$daemonConfigParams = [
			'name' => self::AIO_DAEMON_CONFIG_NAME,
			'display_name' => 'AIO master container daemon',
			'accepts_deploy_id' => $this->getAcceptsDeployId(),
			'protocol' => 'http',
			'host' => self::MASTER_CONTAINER_HOST, // using AIO master container as host
			'deploy_config' => $deployConfig,
		];

		return $this->daemonConfigService->registerDaemonConfig($daemonConfigParams);
	}

	/**
	 * Detecting AIO instance by config setting or AIO_TOKEN env as fallback
	 *
	 * @return bool
	 */
	public function isAIO(): bool {
		$oneClickInstance = $this->config->getSystemValue('one-click-instance', false);
		if (!$oneClickInstance) {
			$oneClickInstance = $this->getAIOMasterToken() !== '';
		}
		return $oneClickInstance;
	}

	/**
	 * Get AIO_TOKEN required for AIO master container API requests authentication
	 *
	 * @return string|null
	 */
	public function getAIOMasterToken(): ?string {
		$aioToken = getenv('AIO_TOKEN');
		if (!$aioToken) {
			return null;
		}
		return $aioToken;
	}

	/**
	 * Build AIO container json params from deployParams
	 *
	 * @param array $deployParams [container_params, image_params]
	 *
	 * @return array valid AIO container-schema.json container declaration
	 */
	public function convertToAIOContainerJson(array $deployParams): array {
		$containerParams = $deployParams['container_params'];
		$imageParams = $deployParams['image_params'];
		$envs = $this->extractDeployEnvs($containerParams['env']);
		return [
			'container_name' => $containerParams['name'],
			'depends_on' => [
				self::AIO_NEXTCLOUD_CONTAINER,
			],
			'display_name' => $envs['APP_DISPLAY_NAME'],
			'image' => $this->buildImageName($imageParams),
			'init' => true,
			'secrets' => [],
			'volumes' => array_map(function (array $exAppVolume) {
				return [
					'source' => $exAppVolume['Source'],
					'destination' => $exAppVolume['Target'],
					'writeable' => $exAppVolume['ReadOnly'],
				];
			}, $deployParams['volumes']),
			'environment' => $containerParams['env'],
			'restart' => 'no', // Do not restart container as it might consume a lot of memory (e.g. in case of ML stuff)
			'devices' => $containerParams['devices'], // To be updated in AppEcosystemV2 controller on AIO API side
			'networks' => $containerParams['net'],
			'read_only' => false,
		];
	}

	public function buildImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' . $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	/**
	 * Init guzzleClient with required certs if configured in deployConfig.
	 * Set up with disabled SSL verification as it is used for communication locally but via https
	 *
	 * @param DaemonConfig $daemonConfig
	 *
	 * @return void
	 */
	public function initGuzzleClient(DaemonConfig $daemonConfig): void {
		if ($daemonConfig->getName() === self::AIO_DAEMON_CONFIG_NAME) {
			$guzzleParams = [
				'verify' => false, // disable SSL verification for local https requests to AIO master container
			];
		} else {
			$guzzleParams = [];
		}
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

	/**
	 * Build docker url. Setup fixed url if default AIO daemonConfig used.
	 *
	 * @param DaemonConfig $daemonConfig
	 *
	 * @return string
	 */
	public function buildDockerUrl(DaemonConfig $daemonConfig): string {
		if ($daemonConfig->getName() === self::AIO_DAEMON_CONFIG_NAME) {
			return self::MASTER_CONTAINER_HOST;
		}
		$dockerUrl = 'http://localhost';
		if (in_array($daemonConfig->getProtocol(), ['http', 'https'])) {
			$dockerUrl = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		}
		return $dockerUrl;
	}

	/**
	 * Using default nextcloud overwrite.cli.url config setting
	 *
	 * @return string
	 */
	private function getNextcloudUrl(): string {
		return str_replace('https', 'http', $this->urlGenerator->getAbsoluteURL(''));
	}

	public function buildApiUrl(string $dockerUrl, string $route): string {
		return sprintf('%s/%s/%s', $dockerUrl, self::AIO_DOCKER_API, $route);
	}

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

	public function healthcheckContainer(string $containerId, DaemonConfig $daemonConfig): bool {
		$attempts = 0;
		$totalAttempts = 60; // ~60 seconds for container to initialize
		while ($attempts < $totalAttempts) {
			$containerInfo = $this->inspectContainer($this->buildDockerUrl($daemonConfig), $containerId);
			if ($containerInfo['State']['Status'] === 'running') {
				return true;
			}
			$attempts++;
			sleep(1);
		}
		return false;
	}
}
