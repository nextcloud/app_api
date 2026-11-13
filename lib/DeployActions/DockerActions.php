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
use OCA\AppAPI\Service\DaemonConfigService;
use OCA\AppAPI\Service\ExAppDeployOptionsService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class DockerActions implements IDeployActions {
	public const DOCKER_API_VERSION = 'v1.44';
	public const EX_APP_CONTAINER_PREFIX = 'nc_app_';
	public const APP_API_HAPROXY_USER = 'app_api_haproxy_user';
	public const DEPLOY_ID = 'docker-install';
	public const DOCKER_SOCKET_PROXY_ERROR = 'Docker Socket Proxy daemons are no longer supported, use a HaRP daemon.';
	public const DOCKER_SOCKET_PROXY_EXAPP_ERROR = 'Docker Socket Proxy daemons are no longer supported. To move the ExApp, uninstall it keeping its data and install it on a HaRP daemon on the same Docker host.';

	private Client $guzzleClient;

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly ICertificateManager $certificateManager,
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly AppAPICommonService $service,
		private readonly ExAppService $exAppService,
		private readonly ICrypto $crypto,
		private readonly ExAppDeployOptionsService $exAppDeployOptionsService,
	) {
	}

	public function getAcceptsDeployId(): string {
		return self::DEPLOY_ID;
	}

	/**
	 * A Docker daemon without HaRP: Docker Socket Proxy or a Docker socket
	 */
	public static function isDockerSocketProxy(DaemonConfig $daemonConfig): bool {
		return $daemonConfig->getAcceptsDeployId() === self::DEPLOY_ID && !HarpService::isHarp($daemonConfig->getDeployConfig() ?? []);
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
			'start_container' => true
		];

		if (isset($params['container_params']['resourceLimits']) && !empty($params['container_params']['resourceLimits'])) {
			$createPayload['resource_limits'] = $params['container_params']['resourceLimits'];
		}

		$logPayload = $createPayload;
		$logPayload['environment_variables'] = '[REDACTED]';
		$this->logger->debug(sprintf('Payload for /docker/exapp/create for %s: %s', $exAppName, json_encode($logPayload)));
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/%s', $dockerUrl, 'docker/exapp/create'),
				['json' => $createPayload],
			);

			if ($response->getStatusCode() !== 201) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to create ExApp container %s. Status: %d, Body: %s', $exAppName, $response->getStatusCode(), $errorBody));
				return sprintf('Failed to create ExApp container (status %d). Check HaRP logs. Details: %s', $response->getStatusCode(), $errorBody);
			}

			$responseData = json_decode((string)$response->getBody(), true);
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
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to install certificates for ExApp "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $statusCode, $errorBody));
			}

		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException during certificate installation for ExApp "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Unexpected exception during certificate installation for ExApp "%s" (instance "%s"): %s', $exAppName, $instanceId, $e->getMessage()), ['exception' => $e]);
		}
	}

	public function getDockerApiVersion(): string {
		$version = $this->appConfig->getValueString(Application::APP_ID, 'docker_api_version', lazy: true);
		if ($version !== '') {
			return $version;
		}
		return self::DOCKER_API_VERSION;
	}

	public function buildApiUrl(string $dockerUrl, string $route): string {
		return sprintf('%s/%s/%s', $dockerUrl, $this->getDockerApiVersion(), $route);
	}

	public function buildBaseImageName(array $imageParams, DaemonConfig $daemonConfig): string {
		return DaemonConfigService::resolveImageRegistry($daemonConfig->getDeployConfig(), $imageParams['image_src']) . '/'
			. $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	private function buildExtendedImageName(array $imageParams, DaemonConfig $daemonConfig): ?string {
		$deployConfig = $daemonConfig->getDeployConfig();
		if (empty($deployConfig['computeDevice']['id'])) {
			return null;
		}
		return DaemonConfigService::resolveImageRegistry($deployConfig, $imageParams['image_src']) . '/'
			. $imageParams['image_name'] . ':' . $imageParams['image_tag'] . '-' . $deployConfig['computeDevice']['id'];
	}

	private function shouldPullImage(array $imageParams, DaemonConfig $daemonConfig): bool {
		return DaemonConfigService::resolveRegistryTarget($daemonConfig->getDeployConfig(), $imageParams['image_src'])
			!== DaemonConfigService::LOCAL_REGISTRY;
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

	public function pullImage(
		string $dockerUrl, array $params, ExApp $exApp, int $startPercent, int $maxPercent, DaemonConfig $daemonConfig, string &$imageId,
	): string {
		$shouldPull = $this->shouldPullImage($params, $daemonConfig);
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
					sprintf('Failed to pull "extended" image via "%s", GuzzleException occur: %s', $dockerUrl, $e->getMessage())
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
			$r = sprintf('Failed to pull image via "%s", GuzzleException occur: %s', $dockerUrl, $e->getMessage());
		}
		return $r;
	}

	/**
	 * @throws GuzzleException
	 */
	public function pullImageInternal(
		string $dockerUrl, ExApp $exApp, int $startPercent, int $maxPercent, string $imageId,
	): string {
		# docs: https://github.com/docker/compose/blob/main/pkg/compose/pull.go
		$layerInProgress = ['preparing', 'waiting', 'pulling fs layer', 'download', 'extracting', 'verifying checksum'];
		$layerFinished = ['already exists', 'pull complete'];
		$disableProgressTracking = false;
		$url = $this->buildApiUrl($dockerUrl, sprintf('images/create?fromImage=%s', urlencode($imageId)));
		$response = $this->guzzleClient->post($url, ['stream' => true]);
		if ($response->getStatusCode() !== 200) {
			return sprintf('Pulling ExApp Image: %s return status code: %d', $imageId, $response->getStatusCode());
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
							sprintf('Progress tracking of image pulling(%s) disabled, error: %d, data: %s', $exApp->getAppid(), json_last_error(), $line)
						);
						$disableProgressTracking = true;
					}
				}
			} catch (Exception $e) {
				$this->logger->warning(
					sprintf('Progress tracking of image pulling(%s) disabled, exception: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]
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
			return json_decode((string)$response->getBody(), true);
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
		return array_reduce($this->processDockerLogs((string)$response->getBody()), function ($carry, $logEntry) {
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
			$this->logger->error('Could not connect to Docker daemon via {url}', ['exception' => $e, 'url' => $url]);
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
			$errorBody = (string)$response->getBody();
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
				$responseData = json_decode((string)$response->getBody(), true);
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
				$errorBody = (string)$response->getBody();
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
				$errorBody = (string)$existsResponse->getBody();
				$this->logger->error(sprintf('Failed to check existence for ExApp "%s" (instance "%s"). Status: %d, Body: %s', $exAppName, $instanceId, $existsStatusCode, $errorBody));
				return sprintf('Failed to check existence for ExApp "%s" (Status: %d). Details: %s', $exAppName, $existsStatusCode, $errorBody);
			}

			$existsData = json_decode((string)$existsResponse->getBody(), true);
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
				$errorBody = (string)$removeResponse->getBody();
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
				$errorBody = (string)$existsResponse->getBody();
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
		$appId = (string)$appInfo['id'];
		$externalApp = $appInfo['external-app'];
		$deployConfig = $daemonConfig->getDeployConfig();
		$storage = '/' . $this->buildExAppVolumeName($appId);

		$imageParams = [
			'image_src' => (string)($externalApp['docker-install']['registry'] ?? 'docker.io'),
			'image_name' => (string)($externalApp['docker-install']['image'] ?? $appId),
			'image_tag' => (string)($externalApp['docker-install']['image-tag'] ?? 'latest'),
		];

		$harpEnvVars = [];
		if (isset($deployConfig['harp']) && !HarpService::isHarpDirectConnect($daemonConfig->getDeployConfig())) {
			$harpEnvVars['HP_FRP_ADDRESS'] = explode(':', $deployConfig['harp']['frp_address'])[0];
			$harpEnvVars['HP_FRP_PORT'] = explode(':', $deployConfig['harp']['frp_address'])[1];
			$harpEnvVars['HP_SHARED_KEY'] = $this->crypto->decrypt($deployConfig['haproxy_password']);
		}

		$envs = $this->buildDeployEnvs([
			'appid' => $appId,
			'name' => (string)$appInfo['name'],
			'version' => (string)$appInfo['version'],
			'host' => $this->service->buildExAppHost($deployConfig),
			'port' => $appInfo['port'],
			'storage' => $storage,
			'secret' => $appInfo['secret'],
			'environment_variables' => $appInfo['external-app']['environment-variables'] ?? [],
			'harp_env_vars' => $harpEnvVars,
		], $deployConfig);

		$containerParams = [
			'name' => $appId,
			'net' => $deployConfig['net'] ?? 'host',
			'env' => $envs,
			'computeDevice' => $deployConfig['computeDevice'] ?? null,
			'mounts' => $appInfo['external-app']['mounts'] ?? [],
			'resourceLimits' => AppAPICommonService::buildResourceLimits($deployConfig)
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
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth,
	): string {
		if (boolval($deployConfig['harp'] ?? false)) {
			$url = rtrim($deployConfig['nextcloud_url'], '/');
			if (str_ends_with($url, '/index.php')) {
				$url = substr($url, 0, -10);
			}
			return sprintf('%s/exapps/%s', $url, $appId);
		}

		// ExApps deployed by a Docker Socket Proxy daemon are reached directly
		$auth = [];
		if (isset($deployConfig['additional_options']['OVERRIDE_APP_HOST'])
			&& $deployConfig['additional_options']['OVERRIDE_APP_HOST'] !== ''
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
		$url = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		if (boolval($daemonConfig->getDeployConfig()['harp'] ?? false)) {
			// if there is a trailling slash, remove it
			$url = rtrim($url, '/') . '/exapps/app_api';
		}
		return $url;
	}

	public function initGuzzleClient(DaemonConfig $daemonConfig): void {
		$guzzleParams = [];
		if ($daemonConfig->getProtocol() === 'https') {
			$guzzleParams = $this->setupCerts($guzzleParams);
		}
		if (isset($daemonConfig->getDeployConfig()['haproxy_password']) && $daemonConfig->getDeployConfig()['haproxy_password'] !== '') {
			$haproxyPass = $this->crypto->decrypt($daemonConfig->getDeployConfig()['haproxy_password']);
			$guzzleParams['auth'] = [self::APP_API_HAPROXY_USER, $haproxyPass];
		}
		if (boolval($daemonConfig->getDeployConfig()['harp'] ?? false)) {
			$guzzleParams['headers'] = [
				'harp-shared-key' => $guzzleParams['auth'][1] ?? '',
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

	public function buildExAppContainerName(string $appId): string {
		return self::EX_APP_CONTAINER_PREFIX . $appId;
	}

	public function buildExAppVolumeName(string $appId): string {
		return self::EX_APP_CONTAINER_PREFIX . $appId . '_data';
	}
}
