<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
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
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Kubernetes deploy actions for ExApps.
 * All K8s operations go through HaRP (no direct K8s API communication from AppAPI).
 */
class KubernetesActions implements IDeployActions {
	public const DEPLOY_ID = 'kubernetes-install';
	public const APP_API_HAPROXY_USER = 'app_api_haproxy_user';

	private Client $guzzleClient;

	public function __construct(
		private readonly LoggerInterface $logger,
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
	 * Deploy ExApp to Kubernetes via HaRP.
	 *
	 * Deployment flow:
	 * 1. Set progress 0%
	 * 2. Call /k8s/exapp/exists - check if already exists
	 * 3. If exists, call /k8s/exapp/remove (cleanup)
	 * 4. Set progress 50%
	 * 5. Call /k8s/exapp/create - create Deployment + PVC
	 * 6. Set progress 70%
	 * 7. Call /k8s/exapp/start - scale to 1 replica
	 * 8. Set progress 80%
	 * 9. Call /k8s/exapp/install_certificates - no-op but keeps consistency
	 * 10. Set progress 90%
	 * 11. Call /k8s/exapp/wait_for_start - wait for Pod ready
	 * 12. Set progress 100%
	 */
	public function deployExApp(ExApp $exApp, DaemonConfig $daemonConfig, array $params = []): string {
		if (!isset($params['image_params'])) {
			return 'Missing image_params.';
		}
		if (!isset($params['container_params'])) {
			return 'Missing container_params.';
		}

		$harpUrl = $this->buildHarpK8sUrl($daemonConfig);
		$this->initGuzzleClient($daemonConfig);

		$exAppName = $params['container_params']['name'];
		$instanceId = '';

		// Step 1: Set progress 0%
		$this->exAppService->setAppDeployProgress($exApp, 0);

		// Step 2: Check if deployment exists
		$error = $this->checkExists($harpUrl, $exAppName, $instanceId, $exists);
		if ($error) {
			return $error;
		}

		// Step 3: If exists, remove it (cleanup)
		if ($exists) {
			$this->logger->info(sprintf('K8s deployment for ExApp "%s" exists. Removing for clean install...', $exAppName));
			$error = $this->removeExApp($harpUrl, $exAppName, removeData: false);
			if ($error) {
				return $error;
			}
		}

		// Step 4: Set progress 50%
		$this->exAppService->setAppDeployProgress($exApp, 50);

		// Step 5: Create Deployment + PVC
		$error = $this->createExApp($harpUrl, $exAppName, $instanceId, $params);
		if ($error) {
			return $error;
		}

		// Step 6: Set progress 70%
		$this->exAppService->setAppDeployProgress($exApp, 70);

		// Step 7: Start (scale to 1 replica)
		$error = $this->startExApp($harpUrl, $exAppName);
		if ($error) {
			return $error;
		}

		// Step 8: Set progress 80%
		$this->exAppService->setAppDeployProgress($exApp, 80);

		// Step 9: Install certificates (no-op for K8s but keeps consistency)
		$this->installCertificates($harpUrl, $exAppName, $instanceId);

		// Step 10: Set progress 90%
		$this->exAppService->setAppDeployProgress($exApp, 90);

		// Save deploy options
		$this->exAppDeployOptionsService->removeExAppDeployOptions($exApp->getAppid());
		$this->exAppDeployOptionsService->addExAppDeployOptions($exApp->getAppid(), $params['deploy_options'] ?? []);

		// Step 11: Wait for Pod ready
		if (!$this->waitExAppStart($harpUrl, $exAppName)) {
			return 'Kubernetes Pod startup failed';
		}

		// Step 12: Set progress 100%
		$this->exAppService->setAppDeployProgress($exApp, 100);
		return '';
	}

	/**
	 * Check if K8s deployment exists.
	 */
	private function checkExists(string $harpUrl, string $exAppName, string $instanceId, ?bool &$exists): string {
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/exists', $harpUrl),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					]
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode !== 200) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to check K8s existence for ExApp "%s". Status: %d, Body: %s', $exAppName, $statusCode, $errorBody));
				return sprintf('Failed to check K8s existence for ExApp "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody);
			}

			$data = json_decode((string)$response->getBody(), true);
			if ($data === null) {
				$this->logger->error(sprintf('Invalid JSON response from /k8s/exapp/exists for ExApp "%s".', $exAppName));
				return sprintf('Invalid JSON response from HaRP /k8s/exapp/exists for ExApp "%s".', $exAppName);
			}

			$exists = $data['exists'] ?? false;
			return '';
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException checking K8s existence for ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to check K8s existence for ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception checking K8s existence for ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error checking K8s existence for ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	/**
	 * Create K8s Deployment + PVC via HaRP.
	 */
	private function createExApp(string $harpUrl, string $exAppName, string $instanceId, array $params): string {
		$computeDevice = 'cpu';
		if (isset($params['container_params']['computeDevice']['id'])) {
			$computeDevice = $params['container_params']['computeDevice']['id'];
		}

		$createPayload = [
			'name' => $exAppName,
			'instance_id' => $instanceId,
			'image' => $this->buildImageName($params['image_params']),
			'environment_variables' => $params['container_params']['env'] ?? [],
			'compute_device' => $computeDevice,
		];

		if (isset($params['container_params']['resourceLimits']) && !empty($params['container_params']['resourceLimits'])) {
			$createPayload['resource_limits'] = $params['container_params']['resourceLimits'];
		}

		$this->logger->debug(sprintf('Payload for /k8s/exapp/create for %s: %s', $exAppName, json_encode($createPayload)));

		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/create', $harpUrl),
				['json' => $createPayload]
			);

			if ($response->getStatusCode() !== 201) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to create K8s ExApp %s. Status: %d, Body: %s', $exAppName, $response->getStatusCode(), $errorBody));
				return sprintf('Failed to create K8s ExApp (status %d). Check HaRP logs. Details: %s', $response->getStatusCode(), $errorBody);
			}

			$responseData = json_decode((string)$response->getBody(), true);
			if ($responseData === null || !isset($responseData['name'])) {
				$this->logger->error(sprintf('Invalid JSON response from HaRP /k8s/exapp/create for %s: %s', $exAppName, $response->getBody()));
				return 'Invalid response from HaRP agent after K8s deployment creation.';
			}

			$this->logger->info(sprintf('K8s Deployment %s created successfully for ExApp %s.', $responseData['name'], $exAppName));
			return '';
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException during HaRP /k8s/exapp/create for %s: %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return 'Failed to communicate with HaRP agent for K8s deployment creation: ' . $e->getMessage();
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception during HaRP /k8s/exapp/create for %s: %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return 'An unexpected error occurred while creating K8s deployment: ' . $e->getMessage();
		}
	}

	/**
	 * Start K8s ExApp (scale to 1 replica).
	 */
	public function startExApp(string $harpUrl, string $exAppName, bool $ignoreIfAlready = false): string {
		$instanceId = '';
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/start', $harpUrl),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					]
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('K8s ExApp "%s" successfully started (scaled to 1 replica).', $exAppName));
				return '';
			}
			if ($statusCode === 200) {
				if ($ignoreIfAlready) {
					$this->logger->info(sprintf('K8s ExApp "%s" was already running.', $exAppName));
					return '';
				} else {
					$errorMsg = sprintf('K8s ExApp "%s" was already running.', $exAppName);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			}

			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to start K8s ExApp "%s". Status: %d, Body: %s', $exAppName, $statusCode, $errorBody));
			return sprintf('Failed to start K8s ExApp "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while starting K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to start K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while starting K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error while starting K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	/**
	 * Stop K8s ExApp (scale to 0 replicas).
	 */
	public function stopExApp(string $harpUrl, string $exAppName, bool $ignoreIfAlready = false): string {
		$instanceId = '';
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/stop', $harpUrl),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					]
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('K8s ExApp "%s" successfully stopped (scaled to 0 replicas).', $exAppName));
				return '';
			}
			if ($statusCode === 200) {
				if ($ignoreIfAlready) {
					$this->logger->info(sprintf('K8s ExApp "%s" was already stopped.', $exAppName));
					return '';
				} else {
					$errorMsg = sprintf('K8s ExApp "%s" was already stopped.', $exAppName);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			}

			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to stop K8s ExApp "%s". Status: %d, Body: %s', $exAppName, $statusCode, $errorBody));
			return sprintf('Failed to stop K8s ExApp "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while stopping K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to stop K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while stopping K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error while stopping K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	/**
	 * Install certificates (no-op for K8s but keeps consistency with Docker).
	 */
	private function installCertificates(string $harpUrl, string $exAppName, string $instanceId): void {
		try {
			$this->logger->info(sprintf('Starting certificate installation process for K8s ExApp "%s".', $exAppName));

			$payload = [
				'name' => $exAppName,
				'instance_id' => $instanceId,
				'system_certs_bundle' => null,
				'install_frp_certs' => false, // K8s always uses direct connect (Service-based routing)
			];

			$bundlePath = $this->certificateManager->getAbsoluteBundlePath();
			if (file_exists($bundlePath) && is_readable($bundlePath)) {
				$payload['system_certs_bundle'] = file_get_contents($bundlePath);
				if ($payload['system_certs_bundle'] === false) {
					$this->logger->warning(sprintf('Failed to read system CA bundle from "%s" for K8s ExApp "%s".', $bundlePath, $exAppName));
					$payload['system_certs_bundle'] = null;
				}
			} else {
				$this->logger->warning(sprintf('System CA bundle not found or not readable at "%s" for K8s ExApp "%s".', $bundlePath, $exAppName));
			}

			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/install_certificates', $harpUrl),
				[
					'json' => $payload,
					'timeout' => 180,
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('Certificate installation completed for K8s ExApp "%s".', $exAppName));
			} else {
				$errorBody = (string)$response->getBody();
				$this->logger->warning(sprintf('Certificate installation for K8s ExApp "%s" returned status %d: %s', $exAppName, $statusCode, $errorBody));
			}
		} catch (GuzzleException $e) {
			$this->logger->warning(sprintf('GuzzleException during certificate installation for K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
		} catch (Exception $e) {
			$this->logger->warning(sprintf('Exception during certificate installation for K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
		}
	}

	/**
	 * Wait for K8s Pod to be ready.
	 */
	public function waitExAppStart(string $harpUrl, string $exAppName): bool {
		$instanceId = '';
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/wait_for_start', $harpUrl),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
					],
					// HaRP's image_pull wait (3600s) + startup_timeout (90s)
					'timeout' => 3700,
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 200) {
				$responseData = json_decode((string)$response->getBody(), true);
				if ($responseData === null) {
					$this->logger->error(sprintf('Invalid JSON response from HaRP /k8s/exapp/wait_for_start for ExApp "%s".', $exAppName));
					return false;
				}

				$started = $responseData['started'] ?? false;
				$status = $responseData['status'] ?? 'unknown';
				$reason = $responseData['reason'] ?? '';

				if ($started === true) {
					$this->logger->info(sprintf('K8s Pod for ExApp "%s" is ready. Status: %s', $exAppName, $status));
					return true;
				} else {
					$this->logger->warning(sprintf('K8s Pod for ExApp "%s" did not become ready. Status: %s, Reason: %s', $exAppName, $status, $reason));
					return false;
				}
			} else {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to wait for K8s ExApp "%s" start. Status: %d, Body: %s', $exAppName, $statusCode, $errorBody));
				return false;
			}
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while waiting for K8s ExApp "%s" start: %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return false;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while waiting for K8s ExApp "%s" start: %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Remove K8s ExApp (Deployment, Service, PVC).
	 */
	public function removeExApp(string $harpUrl, string $exAppName, bool $removeData = false): string {
		$instanceId = '';
		try {
			// First check if exists
			$error = $this->checkExists($harpUrl, $exAppName, $instanceId, $exists);
			if ($error) {
				return $error;
			}

			if (!$exists) {
				$this->logger->info(sprintf('K8s ExApp "%s" does not exist. No removal needed.', $exAppName));
				return '';
			}

			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/remove', $harpUrl),
				[
					'json' => [
						'name' => $exAppName,
						'instance_id' => $instanceId,
						'remove_data' => $removeData,
					]
				]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('K8s ExApp "%s" successfully removed.', $exAppName));
				return '';
			}

			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to remove K8s ExApp "%s". Status: %d, Body: %s', $exAppName, $statusCode, $errorBody));
			return sprintf('Failed to remove K8s ExApp "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while removing K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to remove K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while removing K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error while removing K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	/**
	 * Expose K8s ExApp (create Service) and get upstream endpoint.
	 */
	public function exposeExApp(string $harpUrl, string $exAppName, int $port, array $k8sConfig): array {
		$instanceId = '';
		try {
			$exposePayload = [
				'name' => $exAppName,
				'instance_id' => $instanceId,
				'port' => $port,
				'expose_type' => $k8sConfig['expose_type'] ?? 'clusterip',
				'upstream_host' => $k8sConfig['upstream_host'] ?? null,
				'node_port' => $k8sConfig['node_port'] ?? null,
				'external_traffic_policy' => $k8sConfig['external_traffic_policy'] ?? null,
				'load_balancer_ip' => $k8sConfig['load_balancer_ip'] ?? null,
				'node_address_type' => $k8sConfig['node_address_type'] ?? 'InternalIP',
			];

			$this->logger->debug(sprintf('Payload for /k8s/exapp/expose for %s: %s', $exAppName, json_encode($exposePayload)));

			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/expose', $harpUrl),
				['json' => $exposePayload]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode !== 200) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to expose K8s ExApp "%s". Status: %d, Body: %s', $exAppName, $statusCode, $errorBody));
				return ['error' => sprintf('Failed to expose K8s ExApp "%s" (Status: %d). Details: %s', $exAppName, $statusCode, $errorBody)];
			}

			$responseData = json_decode((string)$response->getBody(), true);
			if ($responseData === null) {
				$this->logger->error(sprintf('Invalid JSON response from HaRP /k8s/exapp/expose for ExApp "%s".', $exAppName));
				return ['error' => sprintf('Invalid JSON response from HaRP /k8s/exapp/expose for ExApp "%s".', $exAppName)];
			}

			$this->logger->info(sprintf('K8s ExApp "%s" exposed successfully. Host: %s, Port: %d', $exAppName, $responseData['host'] ?? 'N/A', $responseData['port'] ?? 0));
			return $responseData;
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while exposing K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return ['error' => sprintf('Failed to communicate with HaRP to expose K8s ExApp "%s": %s', $exAppName, $e->getMessage())];
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while exposing K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return ['error' => sprintf('Unexpected error while exposing K8s ExApp "%s": %s', $exAppName, $e->getMessage())];
		}
	}

	public function buildDeployParams(DaemonConfig $daemonConfig, array $appInfo): array {
		$appId = (string)$appInfo['id'];
		$externalApp = $appInfo['external-app'];
		$deployConfig = $daemonConfig->getDeployConfig();

		// K8s uses a fixed storage path
		$storage = '/nc_app_' . $appId . '_data';

		$imageParams = [
			'image_src' => (string)($externalApp['docker-install']['registry'] ?? 'docker.io'),
			'image_name' => (string)($externalApp['docker-install']['image'] ?? $appId),
			'image_tag' => (string)($externalApp['docker-install']['image-tag'] ?? 'latest'),
		];

		$envs = $this->buildDeployEnvs([
			'appid' => $appId,
			'name' => (string)$appInfo['name'],
			'version' => (string)$appInfo['version'],
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
			'net' => 'bridge', // Not used for K8s but kept for consistency
			'env' => $envs,
			'computeDevice' => $deployConfig['computeDevice'] ?? null,
			'resourceLimits' => $deployConfig['resourceLimits'] ?? []
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
		$computeDeviceId = $deployConfig['computeDevice']['id'] ?? 'cpu';
		$autoEnvs[] = sprintf('COMPUTE_DEVICE=%s', strtoupper($computeDeviceId));

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

	/**
	 * Resolve ExApp URL for K8s deployment.
	 * K8s always uses HaRP for routing, so return HaRP-routed URL.
	 */
	public function resolveExAppUrl(
		string $appId, string $protocol, string $host, array $deployConfig, int $port, array &$auth,
	): string {
		// K8s always uses HaRP mode
		$url = rtrim($deployConfig['nextcloud_url'], '/');
		if (str_ends_with($url, '/index.php')) {
			$url = substr($url, 0, -10);
		}
		return sprintf('%s/exapps/%s', $url, $appId);
	}

	/**
	 * Build the HaRP K8s URL prefix.
	 */
	public function buildHarpK8sUrl(DaemonConfig $daemonConfig): string {
		$url = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		return rtrim($url, '/') . '/exapps/app_api/k8s';
	}

	/**
	 * Build full image name from params.
	 */
	private function buildImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' . $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

	/**
	 * Initialize Guzzle client with HaRP shared key.
	 */
	public function initGuzzleClient(DaemonConfig $daemonConfig): void {
		$guzzleParams = [];

		if ($daemonConfig->getProtocol() === 'https') {
			$guzzleParams = $this->setupCerts($guzzleParams);
		}

		$deployConfig = $daemonConfig->getDeployConfig();
		if (isset($deployConfig['haproxy_password']) && $deployConfig['haproxy_password'] !== '') {
			$harpSharedKey = $this->crypto->decrypt($deployConfig['haproxy_password']);
			$guzzleParams['auth'] = [self::APP_API_HAPROXY_USER, $harpSharedKey];
			$guzzleParams['headers'] = [
				'harp-shared-key' => $harpSharedKey,
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
}
