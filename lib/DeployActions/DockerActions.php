<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\DeployActions;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\AppAPICommonService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
use OCP\App\IAppManager;

use OCP\IAppConfig;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Phar;
use PharData;
use Psr\Log\LoggerInterface;

class DockerActions implements IDeployActions {
	public const DOCKER_API_VERSION = 'v1.41';
	public const EX_APP_CONTAINER_PREFIX = 'nc_app_';
	public const APP_API_HAPROXY_USER = 'app_api_haproxy_user';
	public const DEPLOY_ID = 'docker-install';

	private Client $guzzleClient;
	private bool $useSocket = false;  # for `pullImage` function, to detect can be stream used or not.
	private string $socketAddress;

	public function __construct(
		private readonly LoggerInterface           $logger,
		private readonly IAppConfig                $appConfig,
		private readonly IConfig				   $config,
		private readonly ICertificateManager       $certificateManager,
		private readonly IAppManager               $appManager,
		private readonly IURLGenerator             $urlGenerator,
		private readonly AppAPICommonService       $service,
		private readonly ExAppService              $exAppService,
		private readonly ITempManager              $tempManager,
		private readonly ICrypto                   $crypto,
		private readonly ExAppDeployOptionsService $exAppDeployOptionsService,
	) {
	}

	public function getAcceptsDeployId(): string {
		return self::DEPLOY_ID;
	}

	public function deployExApp(ExApp $exApp, DaemonConfig $daemonConfig, array $params = []): string {
		if (!isset($params['image_params'])) {
			return 'Missing image_params.';
		}
		if (!isset($params['container_params'])) {
			return 'Missing container_params.';
		}

		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);

		$this->exAppService->setAppDeployProgress($exApp, 0);
		$imageId = '';
		$result = $this->pullImage($dockerUrl, $params['image_params'], $exApp, 0, 94, $daemonConfig, $imageId);
		if ($result) {
			return $result;
		}

		$this->exAppService->setAppDeployProgress($exApp, 95);
		$containerName = $this->buildExAppContainerName($params['container_params']['name']);
		$containerInfo = $this->inspectContainer($dockerUrl, $containerName);
		if (isset($containerInfo['Id'])) {
			$result = $this->removeContainer($dockerUrl, $containerName);
			if ($result) {
				return $result;
			}
		}
		$this->exAppService->setAppDeployProgress($exApp, 96);
		$result = $this->createContainer($dockerUrl, $imageId, $daemonConfig, $params['container_params']);
		if (isset($result['error'])) {
			return $result['error'];
		}
		$this->exAppService->setAppDeployProgress($exApp, 97);

		$this->updateCerts($dockerUrl, $containerName);
		$this->exAppService->setAppDeployProgress($exApp, 98);

		$result = $this->startContainer($dockerUrl, $containerName);
		if (isset($result['error'])) {
			return $result['error'];
		}

		$this->exAppDeployOptionsService->removeExAppDeployOptions($exApp->getAppid());
		$this->exAppDeployOptionsService->addExAppDeployOptions($exApp->getAppid(), $params['deploy_options']);

		$this->exAppService->setAppDeployProgress($exApp, 99);
		if (!$this->waitTillContainerStart($containerName, $daemonConfig)) {
			return 'container startup failed';
		}
		$this->exAppService->setAppDeployProgress($exApp, 100);
		return '';
	}

	public function deployExAppHarp(ExApp $exApp, DaemonConfig $daemonConfig, array $params = []): string {
		if (!isset($params['image_params'])) {
			return 'Missing image_params.';
		}
		if (!isset($params['container_params'])) {
			return 'Missing container_params.';
		}

		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);

		$this->exAppService->setAppDeployProgress($exApp, 0);
		$imageId = '';
		$error = $this->pullImage($dockerUrl, $params['image_params'], $exApp, 0, 94, $daemonConfig, $imageId);
		if ($error) {
			return $error;
		}

		$this->exAppService->setAppDeployProgress($exApp, 95);

		$exAppName = $params['container_params']['name'];
		$instanceId = '';  // $this->config->getSystemValue('instanceid', '');

		$error = $this->removeExApp($dockerUrl, $exAppName, ignoreIfNotExists: true);
		if ($error) {
			return $error;
		}
		$this->exAppService->setAppDeployProgress($exApp, 96);

		$computeDevice = 'cpu';
		if (isset($params['container_params']['computeDevice']['id'])) {
			$computeDevice = $params['container_params']['computeDevice']['id'];
		}
		$mountPoints = $params['container_params']['mounts'] ?? [];
		if (!is_array($mountPoints)) {
			$mountPoints = [];
		}
		$createPayload = [
			'name' => $exAppName,
			'instance_id' => $instanceId,
			'image_id' => $imageId,
			'network_mode' => $params['container_params']['net'] ?? 'bridge',
			'environment_variables' => $params['container_params']['env'] ?? [],
			'restart_policy' => $this->appConfig->getValueString(Application::APP_ID, 'container_restart_policy', 'unless-stopped', lazy: true),
			'compute_device' => $computeDevice,
			'mount_points' => $mountPoints,
			'start_container' => true,
		];

		$this->logger->debug(sprintf('Payload for /docker/exapp/create for %s: %s', $exAppName, json_encode($createPayload)));
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/create'),
				['json' => $createPayload],
			);

			if ($response->getStatusCode() !== 201) {
				$errorBody = (string) $response->getBody();
				$this->logger->error(sprintf('Failed to create ExApp container %s. Status: %d, Body: %s', $exAppName, $response->getStatusCode(), $errorBody));
				return sprintf('Failed to create ExApp container (status %d). Check HaRP logs. Details: %s', $response->getStatusCode(), $errorBody);
			}

			$responseData = json_decode((string) $response->getBody(), true);
			if ($responseData === null || !isset($responseData['name']) || !isset($responseData['id'])) {
				$this->logger->error(sprintf('Invalid JSON response from HaRP /docker/exapp/create for %s: %s', $exAppName, $response->getBody()));
				return 'Invalid response from HaRP agent after container creation.';
			}
			$this->logger->info(sprintf('Container %s (ID: %s) created successfully for ExApp %s.', $responseData['name'], $responseData['id'], $exAppName));
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException during HaRP /docker/exapp/create for %s: %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return 'Failed to communicate with HaRP agent for container creation: ' . $e->getMessage();
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception during HaRP /docker/exapp/create for %s: %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return 'An unexpected error occurred while creating container: ' . $e->getMessage();
		}
		$this->exAppService->setAppDeployProgress($exApp, 97);

		$this->updateCertsHarp($daemonConfig, $dockerUrl, $exAppName);
		$this->exAppService->setAppDeployProgress($exApp, 98);

		$error = $this->startExApp($dockerUrl, $exAppName);
		if ($error) {
			return $error;
		}

		$this->exAppDeployOptionsService->removeExAppDeployOptions($exApp->getAppid());
		$this->exAppDeployOptionsService->addExAppDeployOptions($exApp->getAppid(), $params['deploy_options']);

		$this->exAppService->setAppDeployProgress($exApp, 99);
		if (!$this->waitExAppStart($dockerUrl, $exAppName)) {
			return 'container startup failed';
		}
		$this->exAppService->setAppDeployProgress($exApp, 100);
		return '';
	}

	private function updateCerts(string $dockerUrl, string $containerName): void {
		try {
			$this->startContainer($dockerUrl, $containerName);

			$osInfo = $this->getContainerOsInfo($dockerUrl, $containerName);
			if (!$this->isSupportedOs($osInfo)) {
				$this->logger->warning(sprintf(
					"Unsupported OS detected for container: %s. OS info: %s",
					$containerName,
					$osInfo
				));
				return;
			}

			$bundlePath = $this->certificateManager->getAbsoluteBundlePath();
			$targetDir = $this->getTargetCertDir($osInfo); // Determine target directory based on OS
			$this->executeCommandInContainer($dockerUrl, $containerName, ['mkdir', '-p', $targetDir]);
			$this->installParsedCertificates($dockerUrl, $containerName, $bundlePath, $targetDir);

			$updateCommand = $this->getCertificateUpdateCommand($osInfo);
			$this->executeCommandInContainer($dockerUrl, $containerName, $updateCommand);
		} catch (Exception $e) {
			$this->logger->warning(sprintf(
				"Failed to update certificates in container: %s. Error: %s",
				$containerName,
				$e->getMessage()
			));
		} finally {
			$this->stopContainer($dockerUrl, $containerName);
		}
	}

	private function updateCertsHarp(DaemonConfig $daemonConfig, string $dockerUrl, string $exAppName): void {
		$instanceId = '';  // $this->config->getSystemValue('instanceid', '');
		try {
			$this->logger->info(sprintf('Starting certificate installation process for ExApp "%s" (instance "%s").', $exAppName, $instanceId));

			$payload = [
				'name' => $exAppName,
				'instance_id' => $instanceId,
				'system_certs_bundle' => null,
				'install_frp_certs' => !HarpService::isHarpDirectConnect($daemonConfig->getDeployConfig()),
			];

			$bundlePath = $this->certificateManager->getAbsoluteBundlePath();
			if (file_exists($bundlePath) && is_readable($bundlePath)) {
				$payload['system_certs_bundle'] = file_get_contents($bundlePath);
				if ($payload['system_certs_bundle'] === false) {
					$this->logger->warning(sprintf('Failed to read system CA bundle from "%s" for ExApp "%s". System certs will not be installed.', $bundlePath, $exAppName));
					$payload['system_certs_bundle'] = null;
				}
			} else {
				$this->logger->warning(sprintf('System CA bundle not found or not readable at "%s" for ExApp "%s". System certs will not be installed.', $bundlePath, $exAppName));
			}

			$response = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/install_certificates'),
				[
					'json' => $payload,
					'timeout' => 180,
				]
			);

			$statusCode = $response->getStatusCode();

			if ($statusCode === 204) {
				$this->logger->info(sprintf('Successfully installed certificates for ExApp "%s" (instance "%s").', $exAppName, $instanceId));
			} else {
				$errorBody = (string) $response->getBody();
				$this->logger->error(sprintf('Failed to install certificates for ExApp "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $statusCode, $errorBody));
			}

		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException during certificate installation for ExApp "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Unexpected exception during certificate installation for ExApp "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
		}
	}

	private function parseCertificatesFromBundle(string $bundlePath): array {
		$contents = file_get_contents($bundlePath);

		// Match only certificates
		preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $contents, $matches);

		return $matches[0] ?? [];
	}

	private function installParsedCertificates(string $dockerUrl, string $containerId, string $bundlePath, string $targetDir): void {
		$certificates = $this->parseCertificatesFromBundle($bundlePath);
		$tempDir = sys_get_temp_dir();

		foreach ($certificates as $index => $certificate) {
			$tempFile = $tempDir . "/{$containerId}_cert_{$index}.crt";
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}
			file_put_contents($tempFile, $certificate);

			// Build the path in the container
			$pathInContainer = $targetDir . "/custom_cert_$index.crt";

			$this->dockerCopy($dockerUrl, $containerId, $tempFile, $pathInContainer);
			unlink($tempFile);
		}
	}

	private function dockerCopy(string $dockerUrl, string $containerId, string $sourcePath, string $pathInContainer): void {
		$archivePath = $this->createTarArchive($sourcePath, $pathInContainer);
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/archive?path=%s', $containerId, urlencode('/')));

		try {
			$archiveData = file_get_contents($archivePath);
			$this->guzzleClient->put($url, [
				'body' => $archiveData,
				'headers' => ['Content-Type' => 'application/x-tar']
			]);
		} catch (Exception $e) {
			throw new Exception(sprintf("Failed to copy %s to container %s: %s", $sourcePath, $containerId, $e->getMessage()));
		} finally {
			if (file_exists($archivePath)) {
				unlink($archivePath);
			}
		}
	}

	private function getTargetCertDir(string $osInfo): string {
		if (stripos($osInfo, 'alpine') !== false) {
			return '/usr/local/share/ca-certificates'; // Alpine Linux
		}

		if (stripos($osInfo, 'debian') !== false || stripos($osInfo, 'ubuntu') !== false) {
			return '/usr/local/share/ca-certificates'; // Debian and Ubuntu
		}

		if (stripos($osInfo, 'centos') !== false || stripos($osInfo, 'almalinux') !== false) {
			return '/etc/pki/ca-trust/source/anchors'; // CentOS and AlmaLinux
		}

		throw new Exception(sprintf('Unsupported OS: %s', $osInfo));
	}

	private function createTarArchive(string $filePath, string $pathInContainer): string {
		$tempFile = $this->tempManager->getTemporaryFile('.tar');
		if ($tempFile === false) {
			throw new Exception("Failed to create tar archive (getTemporaryFile fails).");
		}

		try {
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}

			$archive = new PharData($tempFile, 0, null, Phar::TAR);
			$relativePathInArchive = ltrim($pathInContainer, '/');
			$archive->addFile($filePath, $relativePathInArchive);
		} catch (\Exception $e) {
			// Clean up the temporary file in case of an error
			if (file_exists($tempFile)) {
				unlink($tempFile);
			}
			throw new Exception(sprintf("Failed to create tar archive: %s", $e->getMessage()));
		}
		return $tempFile; // Return the path to the TAR archive
	}

	private function getCertificateUpdateCommand(string $osInfo): string {
		if (stripos($osInfo, 'alpine') !== false) {
			return 'update-ca-certificates';
		}
		if (stripos($osInfo, 'debian') !== false || stripos($osInfo, 'ubuntu') !== false) {
			return 'update-ca-certificates';
		}
		if (stripos($osInfo, 'centos') !== false || stripos($osInfo, 'almalinux') !== false) {
			return 'update-ca-trust extract';
		}
		throw new Exception('Unsupported OS');
	}

	private function getContainerOsInfo(string $dockerUrl, string $containerId): string {
		$command = ['cat', '/etc/os-release'];
		return $this->executeCommandInContainer($dockerUrl, $containerId, $command);
	}

	private function isSupportedOs(string $osInfo): bool {
		return (bool) preg_match('/(alpine|debian|ubuntu|centos|almalinux)/i', $osInfo);
	}

	private function executeCommandInContainer(string $dockerUrl, string $containerId, $command): string {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s/exec', $containerId));
		$payload = [
			'Cmd' => is_array($command) ? $command : explode(' ', $command),
			'AttachStdout' => true,
			'AttachStderr' => true,
		];
		$response = $this->guzzleClient->post($url, ['json' => $payload]);
		$execId = json_decode((string) $response->getBody(), true)['Id'];

		// Start the exec process
		$startUrl = $this->buildApiUrl($dockerUrl, sprintf('exec/%s/start', $execId));
		$startResponse = $this->guzzleClient->post($startUrl, ['json' => ['Detach' => false, 'Tty' => false]]);
		return (string) $startResponse->getBody();
	}

	public function buildApiUrl(string $dockerUrl, string $route): string {
		return sprintf('%s/%s/%s', $dockerUrl, self::DOCKER_API_VERSION, $route);
	}

	public function buildBaseImageName(array $imageParams, DaemonConfig $daemonConfig): string {
		$deployConfig = $daemonConfig->getDeployConfig();
		if (isset($deployConfig['registries'])) { // custom Docker registry, overrides ExApp's image_src
			foreach ($deployConfig['registries'] as $registry) {
				if ($registry['from'] === $imageParams['image_src'] && $registry['to'] !== 'local') { // local target skips image pull, imageId should be unchanged
					$imageParams['image_src'] = rtrim($registry['to'], '/');
					break;
				}
			}
		}
		return $imageParams['image_src'] . '/' .
			$imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	private function buildExtendedImageName(array $imageParams, DaemonConfig $daemonConfig): ?string {
		$deployConfig = $daemonConfig->getDeployConfig();
		if (empty($deployConfig['computeDevice']['id'])) {
			return null;
		}
		if (isset($deployConfig['registries'])) { // custom Docker registry, overrides ExApp's image_src
			foreach ($deployConfig['registries'] as $registry) {
				if ($registry['from'] === $imageParams['image_src'] && $registry['to'] !== 'local') { // local target skips image pull, imageId should be unchanged
					$imageParams['image_src'] = rtrim($registry['to'], '/');
					break;
				}
			}
		}
		return $imageParams['image_src'] . '/' .
			$imageParams['image_name'] . ':' . $imageParams['image_tag'] . '-' . $daemonConfig->getDeployConfig()['computeDevice']['id'];
	}

	private function shouldPullImage(array $imageParams, DaemonConfig $daemonConfig): bool {
		$deployConfig = $daemonConfig->getDeployConfig();
		if (isset($deployConfig['registries'])) { // custom Docker registry, overrides ExApp's image_src
			foreach ($deployConfig['registries'] as $registry) {
				if ($registry['from'] === $imageParams['image_src'] && $registry['to'] === 'local') { // local target skips image pull, imageId should be unchanged
					return false;
				}
			}
		}
		return true;
	}

	public function imageExists(string $dockerUrl, string $imageId): bool {
		$url = $this->buildApiUrl($dockerUrl, sprintf('images/%s/json', $imageId));
		try {
			$response = $this->guzzleClient->get($url);
			return $response->getStatusCode() === 200;
		} catch (GuzzleException $e) {
			if ($e->getCode() !== 404) {
				$this->logger->error('Failed to check image existence', ['exception' => $e]);
			}
			return false;
		}
	}

	public function createContainer(string $dockerUrl, string $imageId, DaemonConfig $daemonConfig, array $params = []): array {
		$createVolumeResult = $this->createVolume($dockerUrl, $this->buildExAppVolumeName($params['name']));
		if (isset($createVolumeResult['error'])) {
			return $createVolumeResult;
		}

		$containerParams = [
			'Image' => $imageId,
			'Hostname' => $params['hostname'],
			'HostConfig' => [
				'NetworkMode' => $params['net'],
				'Mounts' => $this->buildDefaultExAppVolume($params['hostname']),
				'RestartPolicy' => [
					'Name' => $this->appConfig->getValueString(Application::APP_ID, 'container_restart_policy', 'unless-stopped', lazy: true),
				],
			],
			'Env' => $params['env'],
		];

		// Exposing the ExApp's primary port when the installation type is remote and the network is not a "host"
		if (($params['net'] !== 'host') && ($daemonConfig->getProtocol() === 'https')) {
			$exAppMainPort = $params['port'];
			$containerParams['ExposedPorts'] = [
				sprintf('%d/tcp', $exAppMainPort) => (object) [],
				sprintf('%d/udp', $exAppMainPort) => (object) [],
			];
			$containerParams['HostConfig']['PortBindings'] = [
				sprintf('%d/tcp', $exAppMainPort) => [
					['HostPort' => (string)$exAppMainPort, 'HostIp' => '127.0.0.1'],
					['HostPort' => (string)$exAppMainPort, 'HostIp' => '::1'],
				],
				sprintf('%d/udp', $exAppMainPort) => [
					['HostPort' => (string)$exAppMainPort, 'HostIp' => '127.0.0.1'],
					['HostPort' => (string)$exAppMainPort, 'HostIp' => '::1'],
				],
			];
		}

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

		if (isset($params['computeDevice'])) {
			if ($params['computeDevice']['id'] === 'cuda') {
				if (isset($params['deviceRequests'])) {
					$containerParams['HostConfig']['DeviceRequests'] = $params['deviceRequests'];
				} else {
					$containerParams['HostConfig']['DeviceRequests'] = $this->buildDefaultGPUDeviceRequests();
				}
			}
			if ($params['computeDevice']['id'] === 'rocm') {
				if (isset($params['devices'])) {
					$containerParams['HostConfig']['Devices'] = $params['devices'];
				} else {
					$containerParams['HostConfig']['Devices'] = $this->buildDevicesParams(['/dev/kfd', '/dev/dri']);
				}
			}
		}

		if (isset($params['mounts'])) {
			$containerParams['HostConfig']['Mounts'] = array_merge(
				$containerParams['HostConfig']['Mounts'] ?? [],
				array_map(function ($mount) {
					return [
						'Source' => $mount['source'],
						'Target' => $mount['target'],
						'Type' => 'bind', // we don't support other types for now
						'ReadOnly' => $mount['mode'] === 'ro',
					];
				}, $params['mounts'])
			);
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

	public function removeContainer(string $dockerUrl, string $containerId): string {
		$url = $this->buildApiUrl($dockerUrl, sprintf('containers/%s?force=true', $containerId));
		try {
			$response = $this->guzzleClient->delete($url);
			$this->logger->debug(sprintf('StatusCode of container removal: %d', $response->getStatusCode()));
			if ($response->getStatusCode() === 200 || $response->getStatusCode() === 204) {
				return '';
			}
		} catch (GuzzleException $e) {
			if ($e->getCode() === 409) {  // "removal of container ... is already in progress"
				return '';
			}
			$this->logger->error('Failed to remove container', ['exception' => $e]);
			error_log($e->getMessage());
		}
		return sprintf('Failed to remove container: %s', $containerId);
	}

	public function pullImage(
		string $dockerUrl, array $params, ExApp $exApp, int $startPercent, int $maxPercent, DaemonConfig $daemonConfig, string &$imageId
	): string {
		$shouldPull = $this->shouldPullImage($params, $daemonConfig);
		$urlToLog = $this->useSocket ? $this->socketAddress : $dockerUrl;
		$imageId = $this->buildExtendedImageName($params, $daemonConfig);

		if ($imageId) {
			try {
				if ($shouldPull) {
					$r = $this->pullImageInternal($dockerUrl, $exApp, $startPercent, $maxPercent, $imageId);
					if ($r === '') {
						$this->logger->info(sprintf('Successfully pulled "extended" image: %s', $imageId));
						return '';
					}
					$this->logger->info(sprintf('Failed to pull "extended" image(%s): %s', $imageId, $r));
				} elseif ($this->imageExists($dockerUrl, $imageId)) {
					$this->logger->info('Daemon registry mapping set to "local", skipping image pull');
					$this->exAppService->setAppDeployProgress($exApp, $maxPercent);
					return '';
				} else {
					$this->logger->info(sprintf('Image(%s) not found, but daemon registry mapping set to "local", trying base image', $imageId));
				}
			} catch (GuzzleException $e) {
				$this->logger->info(
					sprintf('Failed to pull "extended" image via "%s", GuzzleException occur: %s', $urlToLog, $e->getMessage())
				);
			}
		}

		$imageId = $this->buildBaseImageName($params, $daemonConfig);
		try {
			if ($shouldPull) {
				$this->logger->info(sprintf('Pulling "base" image: %s', $imageId));
				$r = $this->pullImageInternal($dockerUrl, $exApp, $startPercent, $maxPercent, $imageId);
				if ($r === '') {
					$this->logger->info(sprintf('Image(%s) pulled successfully.', $imageId));
				}
			} elseif ($this->imageExists($dockerUrl, $imageId)) {
				$this->logger->info('Daemon registry mapping set to "local", skipping image pull');
				$this->exAppService->setAppDeployProgress($exApp, $maxPercent);
				return '';
			} else {
				$this->logger->warning(sprintf('Image(%s) not found, but daemon registry mapping set to "local", skipping image pull', $imageId));
				return '';
			}
		} catch (GuzzleException $e) {
			$r = sprintf('Failed to pull image via "%s", GuzzleException occur: %s', $urlToLog, $e->getMessage());
		}
		return $r;
	}

	/**
	 * @throws GuzzleException
	 */
	public function pullImageInternal(
		string $dockerUrl, ExApp $exApp, int $startPercent, int $maxPercent, string $imageId
	): string {
		# docs: https://github.com/docker/compose/blob/main/pkg/compose/pull.go
		$layerInProgress = ['preparing', 'waiting', 'pulling fs layer', 'download', 'extracting', 'verifying checksum'];
		$layerFinished = ['already exists', 'pull complete'];
		$disableProgressTracking = false;
		$url = $this->buildApiUrl($dockerUrl, sprintf('images/create?fromImage=%s', urlencode($imageId)));
		if ($this->useSocket) {
			$response = $this->guzzleClient->post($url);
		} else {
			$response = $this->guzzleClient->post($url, ['stream' => true]);
		}
		if ($response->getStatusCode() !== 200) {
			return sprintf('Pulling ExApp Image: %s return status code: %d', $imageId, $response->getStatusCode());
		}
		if ($this->useSocket) {
			return '';
		}
		$lastPercent = $startPercent;
		$layers = [];
		$buffer = '';
		$responseBody = $response->getBody();
		while (!$responseBody->eof()) {
			$buffer .= $responseBody->read(1024);
			try {
				while (($newlinePos = strpos($buffer, "\n")) !== false) {
					$line = substr($buffer, 0, $newlinePos);
					$buffer = substr($buffer, $newlinePos + 1);
					$jsonLine = json_decode(trim($line));
					if ($jsonLine) {
						if (isset($jsonLine->id) && isset($jsonLine->status)) {
							$layerId = $jsonLine->id;
							$status = strtolower($jsonLine->status);
							foreach ($layerInProgress as $substring) {
								if (str_contains($status, $substring)) {
									$layers[$layerId] = false;
									break;
								}
							}
							foreach ($layerFinished as $substring) {
								if (str_contains($status, $substring)) {
									$layers[$layerId] = true;
									break;
								}
							}
						}
					} else {
						$this->logger->warning(
							sprintf("Progress tracking of image pulling(%s) disabled, error: %d, data: %s", $exApp->getAppid(), json_last_error(), $line)
						);
						$disableProgressTracking = true;
					}
				}
			} catch (Exception $e) {
				$this->logger->warning(
					sprintf("Progress tracking of image pulling(%s) disabled, exception: %s", $exApp->getAppid(), $e->getMessage()), ['exception' => $e]
				);
				$disableProgressTracking = true;
			}
			if (!$disableProgressTracking) {
				$completedLayers = count(array_filter($layers));
				$totalLayers = count($layers);
				$newLastPercent = intval($totalLayers > 0 ? (int)($completedLayers / $totalLayers) * ($maxPercent - $startPercent) : 0);
				if ($lastPercent != $newLastPercent) {
					$this->exAppService->setAppDeployProgress($exApp, $newLastPercent);
					$lastPercent = $newLastPercent;
				}
			}
		}
		return '';
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

	/**
	 * @throws GuzzleException
	 */
	public function getContainerLogs(string $dockerUrl, string $containerId, string $tail = 'all'): string {
		$url = $this->buildApiUrl(
			$dockerUrl, sprintf('containers/%s/logs?stdout=true&stderr=true&tail=%s', $containerId, $tail)
		);
		$response = $this->guzzleClient->get($url);
		return array_reduce($this->processDockerLogs((string) $response->getBody()), function ($carry, $logEntry) {
			return $carry . $logEntry['content'];
		}, '');
	}

	private function processDockerLogs($binaryData): array {
		$offset = 0;
		$length = strlen($binaryData);
		$logs = [];

		while ($offset < $length) {
			if ($offset + 8 > $length) {
				break; // Incomplete header, handle this case as needed
			}

			// Unpack the header
			$header = unpack('C1type/C3skip/N1size', substr($binaryData, $offset, 8));
			$offset += 8; // Move past the header

			// Extract the log data based on the size from header
			$logSize = $header['size'];
			if ($offset + $logSize > $length) {
				break; // Incomplete data, handle this case as needed
			}

			$logs[] = [
				'stream_type' => $header['type'] === 1 ? 'stdout' : 'stderr',
				'content' => substr($binaryData, $offset, $logSize)
			];

			$offset += $logSize; // Move to the next log entry
		}

		return $logs;
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

	public function ping(string $dockerUrl): bool {
		$url = $this->buildApiUrl($dockerUrl, '_ping');
		try {
			$response = $this->guzzleClient->get($url, [
				'timeout' => 3,
			]);
			if ($response->getStatusCode() === 200) {
				return true;
			}
		} catch (Exception $e) {
			$urlToLog = $this->useSocket ? $this->socketAddress : $url;
			$this->logger->error('Could not connect to Docker daemon via {url}', ['exception' => $e, 'url' => $urlToLog]);
			error_log($e->getMessage());
		}
		return false;
	}

	public function startExApp(string $dockerUrl, string $exAppName, bool $ignoreIfAlready = false): string {
		$instanceId = '';  // $this->config->getSystemValue('instanceid', '');
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/start'),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					]
				]
			);
			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('ExApp container "%s" (instance "%s") successfully started.', $exAppName, $instanceId));
				return '';
			}
			if ($statusCode === 200) {
				if ($ignoreIfAlready) {
					$this->logger->info(sprintf('ExApp container "%s" (instance "%s") was already started.', $exAppName, $instanceId));
					return '';
				} else {
					$errorMsg = sprintf('ExApp container "%s" (instance "%s") was already started.', $exAppName, $instanceId);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			}
			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to start ExApp container "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $statusCode, $errorBody));
			return sprintf('Failed to start ExApp container "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while trying to start ExApp container "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP agent to start ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Unexpected exception while starting ExApp container "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return sprintf('An unexpected error occurred while starting ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	public function stopExApp(string $dockerUrl, string $exAppName, bool $ignoreIfAlready = false): string {
		$instanceId = '';  // $this->config->getSystemValue('instanceid', '');
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/stop'),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					]
				]
			);
			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('ExApp container "%s" (instance "%s") successfully stopped.', $exAppName, $instanceId));
				return '';
			}
			if ($statusCode === 200) {
				if ($ignoreIfAlready) {
					$this->logger->info(sprintf('ExApp container "%s" (instance "%s") was already stopped.', $exAppName, $instanceId));
					return '';
				} else {
					$errorMsg = sprintf('ExApp container "%s" (instance "%s") was already stopped.', $exAppName, $instanceId);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			}
			$errorBody = (string) $response->getBody();
			$this->logger->error(sprintf('Failed to stop ExApp container "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $statusCode, $errorBody));
			return sprintf('Failed to stop ExApp container "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while trying to stop ExApp container "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP agent to stop ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Unexpected exception while stopping ExApp container "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return sprintf('An unexpected error occurred while stopping ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	public function waitExAppStart(string $dockerUrl, string $exAppName): bool {
		$instanceId = '';  // $this->config->getSystemValue('instanceid', '');
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/wait_for_start'),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					],
					'timeout' => 150,
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 200) {
				$responseData = json_decode((string) $response->getBody(), true);
				if ($responseData === null) {
					$this->logger->error(sprintf('Invalid JSON response from HaRP /docker/exapp/wait_for_start for ExApp "%s" (instance "%s").', $exAppName, $instanceId));
					return false;
				}
				$started = $responseData['started'] ?? false;
				$status = $responseData['status'] ?? 'unknown';
				$health = $responseData['health'] ?? null;
				$reason = $responseData['reason'] ?? '';

				if ($started === true) {
					$this->logger->info(sprintf('ExApp container "%s" (instance "%s") started successfully. Final state: status=%s, health=%s.', $exAppName, $instanceId, $status, $health ?: 'N/A'));
					return true;
				} else {
					$this->logger->warning(sprintf('ExApp container "%s" (instance "%s") did not start successfully. Final state: status=%s, health=%s, reason="%s".', $exAppName, $instanceId, $status, $health ?: 'N/A', $reason));
					return false;
				}
			} else {
				$errorBody = (string) $response->getBody();
				$this->logger->error(sprintf('Failed to wait for ExApp container "%s" (instance "%s") start. Status: %d, Body: %s', $exAppName, $instanceId, $statusCode, $errorBody));
				return false;
			}
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while waiting for ExApp container "%s" (instance "%s") start: %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return false;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Unexpected exception while waiting for ExApp container "%s" (instance "%s") start: %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	public function removeExApp(string $dockerUrl, string $exAppName, bool $removeData = false, bool $ignoreIfNotExists = false): string {
		$instanceId = '';  // $this->config->getSystemValue('instanceid', '');
		try {
			$existsResponse = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/exists'),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					]
				]
			);

			$existsStatusCode = $existsResponse->getStatusCode();
			if ($existsStatusCode !== 200) {
				$errorBody = (string) $existsResponse->getBody();
				$this->logger->error(sprintf('Failed to check existence for ExApp "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $existsStatusCode, $errorBody));
				return sprintf('Failed to check existence for ExApp "%s" (Status: %d). Details: %s', $exAppName, $existsStatusCode, $errorBody);
			}

			$existsData = json_decode((string) $existsResponse->getBody(), true);
			if ($existsData === null) {
				$this->logger->error(sprintf('Invalid JSON response from HaRP /docker/exapp/exists for ExApp "%s" (instance "%s").', $exAppName, $instanceId));
				return sprintf('Invalid JSON response from HaRP /docker/exapp/exists for ExApp "%s".', $exAppName);
			}

			if (isset($existsData['exists']) && $existsData['exists'] === true) {
				$this->logger->info(sprintf('Container for ExApp "%s" (instance "%s") exists. Removing it..', $exAppName, $instanceId));
				$removeResponse = $this->guzzleClient->post(
					sprintf('%s/%s', $dockerUrl, 'docker/exapp/remove'),
					[
						'json' => [
							'name' => $exAppName,
							'instance_id' => $instanceId,
							'remove_data' => $removeData,
						]
					]
				);

				$removeStatusCode = $removeResponse->getStatusCode();
				if ($removeStatusCode === 204) {
					$this->logger->info(sprintf('ExApp container "%s" (instance "%s") successfully removed.', $exAppName, $instanceId));
					return '';
				}
				$errorBody = (string) $removeResponse->getBody();
				$this->logger->error(sprintf('Failed to remove ExApp container "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $removeStatusCode, $errorBody));
				return sprintf('Failed to remove ExApp container "%s" (Status: %d). Details: %s', $exAppName, $removeStatusCode, $errorBody);
			} elseif (isset($existsData['exists']) && $existsData['exists'] === false) {
				if ($ignoreIfNotExists) {
					$this->logger->info(sprintf('ExApp container "%s" (instance "%s") does not exist. No removal needed.', $exAppName, $instanceId));
					return '';
				} else {
					$errorMsg = sprintf('ExApp container "%s" (instance "%s") does not exist and cannot be removed.', $exAppName, $instanceId);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			} else {
				$errorBody = (string) $existsResponse->getBody();
				$this->logger->error(sprintf('Unexpected "exists" data from /docker/exapp/exists for ExApp "%s" (instance "%s"). Body: %s', $exAppName, $instanceId, $errorBody));
				return sprintf('Unexpected "exists" data from HaRP for ExApp "%s".', $exAppName);
			}

		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while trying to remove ExApp container "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP agent to remove ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Unexpected exception while removing ExApp container "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
			return sprintf('An unexpected error occurred while removing ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	public function buildDeployParams(DaemonConfig $daemonConfig, array $appInfo): array {
		$appId = (string) $appInfo['id'];
		$externalApp = $appInfo['external-app'];
		$deployConfig = $daemonConfig->getDeployConfig();

		$deviceRequests = [];
		$devices = [];
		if (isset($deployConfig['computeDevice'])) {
			if ($deployConfig['computeDevice']['id'] === 'cuda') {
				$deviceRequests = $this->buildDefaultGPUDeviceRequests();
			} elseif ($deployConfig['computeDevice']['id'] === 'rocm') {
				$devices = $this->buildDevicesParams(['/dev/kfd', '/dev/dri']);
			}
		}
		$storage = $this->buildDefaultExAppVolume($appId)[0]['Target'];

		$imageParams = [
			'image_src' => (string) ($externalApp['docker-install']['registry'] ?? 'docker.io'),
			'image_name' => (string) ($externalApp['docker-install']['image'] ?? $appId),
			'image_tag' => (string) ($externalApp['docker-install']['image-tag'] ?? 'latest'),
		];

		$harpEnvVars = [];
		if (isset($deployConfig['harp']) && !HarpService::isHarpDirectConnect($daemonConfig->getDeployConfig())) {
			$harpEnvVars['HP_FRP_ADDRESS'] = explode(':', $deployConfig['harp']['frp_address'])[0];
			$harpEnvVars['HP_FRP_PORT'] = explode(':', $deployConfig['harp']['frp_address'])[1];
			$harpEnvVars['HP_SHARED_KEY'] = $this->crypto->decrypt($deployConfig['haproxy_password']);
		}

		$envs = $this->buildDeployEnvs([
			'appid' => $appId,
			'name' => (string) $appInfo['name'],
			'version' => (string) $appInfo['version'],
			'host' => $this->service->buildExAppHost($deployConfig),
			'port' => $appInfo['port'],
			'storage' => $storage,
			'secret' => $appInfo['secret'],
			'environment_variables' => $appInfo['external-app']['environment-variables'] ?? [],
			'harp_env_vars' => $harpEnvVars,
		], $deployConfig);

		$containerParams = [
			'name' => $appId,
			'hostname' => $appId,
			'port' => $appInfo['port'],
			'net' => $deployConfig['net'] ?? 'host',
			'env' => $envs,
			'computeDevice' => $deployConfig['computeDevice'] ?? null,
			'devices' => $devices,
			'deviceRequests' => $deviceRequests,
			'mounts' => $appInfo['external-app']['mounts'] ?? [],
		];

		return [
			'image_params' => $imageParams,
			'container_params' => $containerParams,
			'deploy_options' => [
				'environment_variables' => $appInfo['external-app']['environment-variables'] ?? [],
				'mounts' => $appInfo['external-app']['mounts'] ?? [],
			]
		];
	}

	public function buildDeployEnvs(array $params, array $deployConfig): array {
		$autoEnvs = [
			sprintf('AA_VERSION=%s', $this->appManager->getAppVersion(Application::APP_ID, false)),
			sprintf('APP_SECRET=%s', $params['secret']),
			sprintf('APP_ID=%s', $params['appid']),
			sprintf('APP_DISPLAY_NAME=%s', $params['name']),
			sprintf('APP_VERSION=%s', $params['version']),
			sprintf('APP_HOST=%s', $params['host']),
			sprintf('APP_PORT=%s', $params['port']),
			sprintf('APP_PERSISTENT_STORAGE=%s', $params['storage']),
			sprintf('NEXTCLOUD_URL=%s', $deployConfig['nextcloud_url'] ?? str_replace('https', 'http', $this->urlGenerator->getAbsoluteURL(''))),
		];

		// Always set COMPUTE_DEVICE=CPU|CUDA|ROCM
		$autoEnvs[] = sprintf('COMPUTE_DEVICE=%s', strtoupper($deployConfig['computeDevice']['id']));
		// Add required GPU runtime envs if daemon configured to use GPU
		if (isset($deployConfig['computeDevice'])) {
			if ($deployConfig['computeDevice']['id'] === 'cuda') {
				$autoEnvs[] = sprintf('NVIDIA_VISIBLE_DEVICES=%s', 'all');
				$autoEnvs[] = sprintf('NVIDIA_DRIVER_CAPABILITIES=%s', 'compute,utility');
			}
		}

		// Appending additional deploy options to container envs
		foreach (array_keys($params['environment_variables']) as $envKey) {
			$autoEnvs[] = sprintf('%s=%s', $envKey, $params['environment_variables'][$envKey]['value'] ?? '');
		}

		// HaRP specific environment variables
		foreach ($params['harp_env_vars'] as $envKey => $envValue) {
			$autoEnvs[] = sprintf('%s=%s', $envKey, $envValue);
		}

		return $autoEnvs;
	}

	public function resolveExAppUrl(
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth
	): string {
		if (boolval($deployConfig['harp'] ?? false)) {
			$url = rtrim($deployConfig['nextcloud_url'], '/');
			if (str_ends_with($url, '/index.php')) {
				$url = substr($url, 0, -10);
			}
			return sprintf('%s/exapps/%s', $url, $appId);
		}

		$auth = [];
		if (isset($deployConfig['additional_options']['OVERRIDE_APP_HOST']) &&
			$deployConfig['additional_options']['OVERRIDE_APP_HOST'] !== ''
		) {
			$wideNetworkAddresses = ['0.0.0.0', '127.0.0.1', '::', '::1'];
			if (!in_array($deployConfig['additional_options']['OVERRIDE_APP_HOST'], $wideNetworkAddresses)) {
				return sprintf(
					'%s://%s:%s', $protocol, $deployConfig['additional_options']['OVERRIDE_APP_HOST'], $port
				);
			}
		}
		$host = explode(':', $host)[0];
		if ($protocol == 'https') {
			$exAppHost = $host;
		} elseif (isset($deployConfig['net']) && $deployConfig['net'] === 'host') {
			$exAppHost = 'localhost';
		} else {
			$exAppHost = $appId;
		}
		if ($protocol == 'https' && isset($deployConfig['haproxy_password']) && $deployConfig['haproxy_password'] !== '') {
			// we only set haproxy auth for remote installations, when all requests come through HaProxy.
			$haproxyPass = $this->crypto->decrypt($deployConfig['haproxy_password']);
			$auth = [self::APP_API_HAPROXY_USER, $haproxyPass];
		}
		return sprintf('%s://%s:%s', $protocol, $exAppHost, $port);
	}

	public function waitTillContainerStart(string $containerId, DaemonConfig $daemonConfig): bool {
		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$attempts = 0;
		$totalAttempts = 90; // ~90 seconds for container to start
		while ($attempts < $totalAttempts) {
			$containerInfo = $this->inspectContainer($dockerUrl, $containerId);
			if ($containerInfo['State']['Status'] === 'running') {
				return true;
			}
			$attempts++;
			sleep(1);
		}
		return false;
	}

	public function healthcheckContainer(string $containerId, DaemonConfig $daemonConfig, bool $waitForSuccess): bool {
		$dockerUrl = $this->buildDockerUrl($daemonConfig);
		$maxTotalAttempts = $waitForSuccess ? 900 : 1;
		while ($maxTotalAttempts > 0) {
			$containerInfo = $this->inspectContainer($dockerUrl, $containerId);
			if (!isset($containerInfo['State']['Health']['Status'])) {
				return true;  // container does not support Healthcheck
			}
			$status = $containerInfo['State']['Health']['Status'];
			if ($status === '') {
				return true;  // we treat empty status as 'success', see https://github.com/nextcloud/app_api/issues/439
			}
			if ($status === 'healthy') {
				return true;
			}
			if ($status === 'unhealthy') {
				return false;
			}
			$maxTotalAttempts--;
			if ($maxTotalAttempts > 0) {
				sleep(1);
			}
		}
		return false;
	}

	public function buildDockerUrl(DaemonConfig $daemonConfig): string {
		// When using local socket, we the curl URL needs to be set to http://localhost
		$url = $this->isLocalSocket($daemonConfig->getHost())
			? 'http://localhost'
			: $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		if (boolval($daemonConfig->getDeployConfig()['harp'] ?? false)) {
			// if there is a trailling slash, remove it
			$url = rtrim($url, '/') . '/exapps/app_api';
		}
		return $url;
	}

	public function initGuzzleClient(DaemonConfig $daemonConfig): void {
		$guzzleParams = [];
		if ($this->isLocalSocket($daemonConfig->getHost())) {
			$guzzleParams = [
				'curl' => [
					CURLOPT_UNIX_SOCKET_PATH => $daemonConfig->getHost(),
				],
			];
			$this->useSocket = true;
			$this->socketAddress = $daemonConfig->getHost();
		} elseif ($daemonConfig->getProtocol() === 'https') {
			$guzzleParams = $this->setupCerts($guzzleParams);
		}
		if (isset($daemonConfig->getDeployConfig()['haproxy_password']) && $daemonConfig->getDeployConfig()['haproxy_password'] !== '') {
			$haproxyPass = $this->crypto->decrypt($daemonConfig->getDeployConfig()['haproxy_password']);
			$guzzleParams['auth'] = [self::APP_API_HAPROXY_USER, $haproxyPass];
		}
		if (boolval($daemonConfig->getDeployConfig()['harp'] ?? false)) {
			$guzzleParams['headers'] = [
				'harp-shared-key' => $guzzleParams['auth'][1],
				'docker-engine-port' => $daemonConfig->getDeployConfig()['harp']['docker_socket_port'],
			];
		}
		$this->guzzleClient = new Client($guzzleParams);
	}

	private function setupCerts(array $guzzleParams): array {
		if (!$this->config->getSystemValueBool('installed', false)) {
			$certs = \OC::$SERVERROOT . '/resources/config/ca-bundle.crt';
		} else {
			$certs = $this->certificateManager->getAbsoluteBundlePath();
		}

		$guzzleParams['verify'] = $certs;
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

	public function buildExAppContainerName(string $appId): string {
		return self::EX_APP_CONTAINER_PREFIX . $appId;
	}

	public function buildExAppVolumeName(string $appId): string {
		return self::EX_APP_CONTAINER_PREFIX . $appId . '_data';
	}

	/**
	 * Return default GPU device requests for container.
	 */
	private function buildDefaultGPUDeviceRequests(): array {
		return [
			[
				'Driver' => 'nvidia', // Currently only NVIDIA GPU vendor
				'Count' => -1, // All available GPUs
				'Capabilities' => [['compute', 'utility']], // Compute and utility capabilities
			],
		];
	}

	private function isLocalSocket(string $host): bool {
		$isLocalPath = strpos($host, '/') === 0;
		if ($isLocalPath) {
			if (!file_exists($host)) {
				$this->logger->error('Local docker socket path {path} does not exist', ['path' => $host]);
			} elseif (!is_writable($host)) {
				$this->logger->error('Local docker socket path {path} is not writable', ['path' => $host]);
			}
		}
		return $isLocalPath;
	}
}
