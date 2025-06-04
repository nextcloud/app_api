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
use OCP\App\IAppManager;

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

	private Client $guzzleClient;
	private bool $useSocket = false;  # for `pullImage` function, to detect can be stream used or not.
	private string $socketAddress;

	public function __construct(
		private readonly LoggerInterface           $logger,
		private readonly IConfig                   $config,
		private readonly ICertificateManager       $certificateManager,
		private readonly IAppManager               $appManager,
		private readonly IURLGenerator             $urlGenerator,
		private readonly AppAPICommonService       $service,
		private readonly ExAppService		       $exAppService,
		private readonly ITempManager              $tempManager,
		private readonly ICrypto			       $crypto,
		private readonly ExAppDeployOptionsService $exAppDeployOptionsService,
	) {
	}

	public function getAcceptsDeployId(): string {
		return 'docker-install';
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

	public function buildBaseImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' .
			$imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	private function buildExtendedImageName(array $imageParams, DaemonConfig $daemonConfig): ?string {
		if (empty($daemonConfig->getDeployConfig()['computeDevice']['id'])) {
			return null;
		}
		return $imageParams['image_src'] . '/' .
			$imageParams['image_name'] . '-' . $daemonConfig->getDeployConfig()['computeDevice']['id'] . ':' . $imageParams['image_tag'];
	}

	private function buildExtendedImageName2(array $imageParams, DaemonConfig $daemonConfig): ?string {
		if (empty($daemonConfig->getDeployConfig()['computeDevice']['id'])) {
			return null;
		}
		return $imageParams['image_src'] . '/' .
			$imageParams['image_name'] . ':' . $imageParams['image_tag'] . '-' . $daemonConfig->getDeployConfig()['computeDevice']['id'];
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
					'Name' => $this->config->getAppValue(Application::APP_ID, 'container_restart_policy', 'unless-stopped'),
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
		$imageId = $this->buildExtendedImageName2($params, $daemonConfig);
		if ($imageId) {
			try {
				$r = $this->pullImageInternal($dockerUrl, $exApp, $startPercent, $maxPercent, $imageId);
				if ($r === '') {
					$this->logger->info(sprintf('Successfully pulled "extended" image in a new name format: %s', $imageId));
					return '';
				}
				$this->logger->info(sprintf('Failed to pull "extended" image(%s): %s', $imageId, $r));
			} catch (GuzzleException $e) {
				$this->logger->info(
					sprintf('Failed to pull "extended" image(%s), GuzzleException occur: %s', $imageId, $e->getMessage())
				);
			}
		}
		$imageId = $this->buildExtendedImageName($params, $daemonConfig);  // TODO: remove with drop of NC29 support
		if ($imageId) {
			try {
				$r = $this->pullImageInternal($dockerUrl, $exApp, $startPercent, $maxPercent, $imageId);
				if ($r === '') {
					$this->logger->info(sprintf('Successfully pulled "extended" image in an old name format: %s', $imageId));
					return '';
				}
				$this->logger->info(sprintf('Failed to pull "extended" image(%s): %s', $imageId, $r));
			} catch (GuzzleException $e) {
				$this->logger->info(
					sprintf('Failed to pull "extended" image(%s), GuzzleException occur: %s', $imageId, $e->getMessage())
				);
			}
		}
		$imageId = $this->buildBaseImageName($params);
		$this->logger->info(sprintf('Pulling "base" image: %s', $imageId));
		try {
			$r = $this->pullImageInternal($dockerUrl, $exApp, $startPercent, $maxPercent, $imageId);
			if ($r === '') {
				$this->logger->info(sprintf('Image(%s) pulled successfully.', $imageId));
			}
		} catch (GuzzleException $e) {
			$urlToLog = $this->useSocket ? $this->socketAddress : $dockerUrl;
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
				$newLastPercent = intval($totalLayers > 0 ? ($completedLayers / $totalLayers) * ($maxPercent - $startPercent) : 0);
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

		$envs = $this->buildDeployEnvs([
			'appid' => $appId,
			'name' => (string) $appInfo['name'],
			'version' => (string) $appInfo['version'],
			'host' => $this->service->buildExAppHost($deployConfig),
			'port' => $appInfo['port'],
			'storage' => $storage,
			'secret' => $appInfo['secret'],
			'environment_variables' => $appInfo['external-app']['environment-variables'] ?? [],
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

		return $autoEnvs;
	}

	public function resolveExAppUrl(
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth
	): string {
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
		return $this->isLocalSocket($daemonConfig->getHost()) ? 'http://localhost' : $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
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
