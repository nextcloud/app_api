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
 *
 * Supports multi-role deployments via <k8s-service-roles> in info.xml.
 * When roles are defined, one K8s Deployment is created per role (same image, different env var).
 * Only roles with expose=true get a K8s Service.
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

	public static function getServiceRoles(array $appInfo): array {
		return $appInfo['external-app']['k8s-service-roles'] ?? [];
	}

	/**
	 * Deploy ExApp to Kubernetes via HaRP.
	 *
	 * When k8s-service-roles are present in params, creates one Deployment per role.
	 * Otherwise falls back to single-deployment mode (backward compatible).
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

		$roles = $params['k8s_service_roles'] ?? [];

		if (empty($roles)) {
			return $this->deploySingleExApp($exApp, $harpUrl, $params);
		}

		return $this->deployMultiRoleExApp($exApp, $harpUrl, $params, $roles);
	}

	private function deploySingleExApp(ExApp $exApp, string $harpUrl, array $params): string {
		$exAppName = $params['container_params']['name'];
		$instanceId = '';

		$this->exAppService->setAppDeployProgress($exApp, 0);

		$error = $this->checkExists($harpUrl, $exAppName, $instanceId, $exists);
		if ($error) {
			return $error;
		}

		if ($exists) {
			$this->logger->info(sprintf('K8s deployment for ExApp "%s" exists. Removing for clean install...', $exAppName));
			$error = $this->removeExApp($harpUrl, $exAppName, removeData: false);
			if ($error) {
				return $error;
			}
		}

		$this->exAppService->setAppDeployProgress($exApp, 50);

		$error = $this->createExApp($harpUrl, $exAppName, $instanceId, $params);
		if ($error) {
			return $error;
		}

		$this->exAppService->setAppDeployProgress($exApp, 70);

		$error = $this->startExApp($harpUrl, $exAppName);
		if ($error) {
			return $error;
		}

		$this->exAppService->setAppDeployProgress($exApp, 80);

		$this->installCertificates($harpUrl, $exAppName, $instanceId);

		$this->exAppService->setAppDeployProgress($exApp, 90);

		$this->exAppDeployOptionsService->removeExAppDeployOptions($exApp->getAppid());
		$this->exAppDeployOptionsService->addExAppDeployOptions($exApp->getAppid(), $params['deploy_options'] ?? []);

		if (!$this->waitExAppStart($harpUrl, $exAppName)) {
			return 'Kubernetes Pod startup failed';
		}

		$this->exAppService->setAppDeployProgress($exApp, 100);
		return '';
	}

	/**
	 * Deploy a multi-role ExApp: one Deployment per role.
	 *
	 * @param array $roles Array of role definitions from k8s-service-roles
	 */
	private function deployMultiRoleExApp(ExApp $exApp, string $harpUrl, array $params, array $roles): string {
		$exAppName = $params['container_params']['name'];
		$instanceId = '';
		$totalRoles = count($roles);

		$this->logger->info(sprintf('Deploying ExApp "%s" with %d K8s service roles.', $exAppName, $totalRoles));
		$this->exAppService->setAppDeployProgress($exApp, 0);

		foreach ($roles as $role) {
			$roleSuffix = $role['name'];
			$error = $this->checkExists($harpUrl, $exAppName, $instanceId, $exists, $roleSuffix);
			if ($error) {
				return $error;
			}
			if ($exists) {
				$this->logger->info(sprintf('K8s deployment for ExApp "%s" role "%s" exists. Removing...', $exAppName, $roleSuffix));
				$error = $this->removeExApp($harpUrl, $exAppName, removeData: false, roleSuffix: $roleSuffix);
				if ($error) {
					return $error;
				}
			}
		}

		$this->exAppService->setAppDeployProgress($exApp, 20);

		$roleIndex = 0;
		foreach ($roles as $role) {
			$roleSuffix = $role['name'];
			$roleEnvVar = $role['env'] ?? '';

			$roleParams = $params;
			if ($roleEnvVar !== '') {
				$roleParams['container_params']['env'][] = $roleEnvVar;
			}

			$this->logger->info(sprintf('Creating K8s deployment for ExApp "%s" role "%s" (%d/%d).', $exAppName, $roleSuffix, $roleIndex + 1, $totalRoles));

			$error = $this->createExApp($harpUrl, $exAppName, $instanceId, $roleParams, $roleSuffix);
			if ($error) {
				return $error;
			}

			$error = $this->startExApp($harpUrl, $exAppName, roleSuffix: $roleSuffix);
			if ($error) {
				return $error;
			}

			$this->installCertificates($harpUrl, $exAppName, $instanceId, $roleSuffix);

			$roleIndex++;
			$currentProgress = 20 + (int)(($roleIndex * 60) / $totalRoles);
			$this->exAppService->setAppDeployProgress($exApp, min($currentProgress, 80));
		}

		$this->exAppService->setAppDeployProgress($exApp, 80);

		$this->exAppDeployOptionsService->removeExAppDeployOptions($exApp->getAppid());
		$deployOptions = $params['deploy_options'] ?? [];
		$deployOptions['k8s_service_roles'] = $roles;
		$this->exAppDeployOptionsService->addExAppDeployOptions($exApp->getAppid(), $deployOptions);

		foreach ($roles as $role) {
			$roleSuffix = $role['name'];
			if (!$this->waitExAppStart($harpUrl, $exAppName, $roleSuffix)) {
				return sprintf('Kubernetes Pod startup failed for role "%s"', $roleSuffix);
			}
		}

		$this->exAppService->setAppDeployProgress($exApp, 100);
		return '';
	}

	/**
	 * Expose the appropriate role for a multi-role ExApp.
	 * Only roles with expose=true get a K8s Service.
	 * Returns the expose result for the first exposed role.
	 */
	public function exposeExAppRoles(string $harpUrl, string $exAppName, int $port, array $k8sConfig, array $roles): array {
		foreach ($roles as $role) {
			if (!($role['expose'] ?? false)) {
				continue;
			}
			$roleSuffix = $role['name'];
			$result = $this->exposeExApp($harpUrl, $exAppName, $port, $k8sConfig, $roleSuffix);
			if (isset($result['error'])) {
				return $result;
			}
			return $result;
		}
		return ['error' => 'No exposed roles found in k8s-service-roles configuration.'];
	}

	public function startAllRoles(string $harpUrl, string $exAppName, array $roles): string {
		foreach ($roles as $role) {
			$error = $this->startExApp($harpUrl, $exAppName, ignoreIfAlready: true, roleSuffix: $role['name']);
			if ($error) {
				return $error;
			}
		}
		return '';
	}

	public function stopAllRoles(string $harpUrl, string $exAppName, array $roles): string {
		foreach ($roles as $role) {
			$error = $this->stopExApp($harpUrl, $exAppName, ignoreIfAlready: true, roleSuffix: $role['name']);
			if ($error) {
				return $error;
			}
		}
		return '';
	}

	public function removeAllRoles(string $harpUrl, string $exAppName, array $roles, bool $removeData = false): string {
		foreach ($roles as $role) {
			$error = $this->removeExApp($harpUrl, $exAppName, removeData: $removeData, roleSuffix: $role['name']);
			if ($error) {
				return $error;
			}
		}
		return '';
	}

	private function buildNamePayload(string $exAppName, string $instanceId = '', string $roleSuffix = ''): array {
		$payload = [
			'name' => $exAppName,
			'instance_id' => $instanceId,
		];
		if ($roleSuffix !== '') {
			$payload['role_suffix'] = $roleSuffix;
		}
		return $payload;
	}

	private function logName(string $exAppName, string $roleSuffix = ''): string {
		return $roleSuffix !== '' ? "{$exAppName}/{$roleSuffix}" : $exAppName;
	}

	private function checkExists(string $harpUrl, string $exAppName, string $instanceId, ?bool &$exists, string $roleSuffix = ''): string {
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/exists', $harpUrl),
				['json' => $this->buildNamePayload($exAppName, $instanceId, $roleSuffix)]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode !== 200) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to check K8s existence for ExApp "%s" (role="%s"). Status: %d, Body: %s', $exAppName, $roleSuffix, $statusCode, $errorBody));
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

	private function createExApp(string $harpUrl, string $exAppName, string $instanceId, array $params, string $roleSuffix = ''): string {
		$computeDevice = 'cpu';
		if (isset($params['container_params']['computeDevice']['id'])) {
			$computeDevice = $params['container_params']['computeDevice']['id'];
		}

		$createPayload = $this->buildNamePayload($exAppName, $instanceId, $roleSuffix);
		$createPayload['image'] = $this->buildImageName($params['image_params']);
		$createPayload['environment_variables'] = $params['container_params']['env'] ?? [];
		$createPayload['compute_device'] = $computeDevice;

		if (isset($params['container_params']['resourceLimits']) && !empty($params['container_params']['resourceLimits'])) {
			$createPayload['resource_limits'] = $params['container_params']['resourceLimits'];
		}

		$logName = $this->logName($exAppName, $roleSuffix);
		$this->logger->debug(sprintf('Payload for /k8s/exapp/create for %s: %s', $logName, json_encode($createPayload)));

		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/create', $harpUrl),
				['json' => $createPayload]
			);

			if ($response->getStatusCode() !== 201) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to create K8s ExApp %s. Status: %d, Body: %s', $logName, $response->getStatusCode(), $errorBody));
				return sprintf('Failed to create K8s ExApp (status %d). Check HaRP logs. Details: %s', $response->getStatusCode(), $errorBody);
			}

			$responseData = json_decode((string)$response->getBody(), true);
			if ($responseData === null || !isset($responseData['name'])) {
				$this->logger->error(sprintf('Invalid JSON response from HaRP /k8s/exapp/create for %s: %s', $logName, $response->getBody()));
				return 'Invalid response from HaRP agent after K8s deployment creation.';
			}

			$this->logger->info(sprintf('K8s Deployment %s created successfully for ExApp %s.', $responseData['name'], $logName));
			return '';
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException during HaRP /k8s/exapp/create for %s: %s', $logName, $e->getMessage()), ['exception' => $e]);
			return 'Failed to communicate with HaRP agent for K8s deployment creation: ' . $e->getMessage();
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception during HaRP /k8s/exapp/create for %s: %s', $logName, $e->getMessage()), ['exception' => $e]);
			return 'An unexpected error occurred while creating K8s deployment: ' . $e->getMessage();
		}
	}

	public function startExApp(string $harpUrl, string $exAppName, bool $ignoreIfAlready = false, string $roleSuffix = ''): string {
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/start', $harpUrl),
				['json' => $this->buildNamePayload($exAppName, '', $roleSuffix)]
			);

			$statusCode = $response->getStatusCode();
			$logName = $this->logName($exAppName, $roleSuffix);
			if ($statusCode === 204) {
				$this->logger->info(sprintf('K8s ExApp "%s" successfully started (scaled to 1 replica).', $logName));
				return '';
			}
			if ($statusCode === 200) {
				if ($ignoreIfAlready) {
					$this->logger->info(sprintf('K8s ExApp "%s" was already running.', $logName));
					return '';
				} else {
					$errorMsg = sprintf('K8s ExApp "%s" was already running.', $logName);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			}

			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to start K8s ExApp "%s". Status: %d, Body: %s', $logName, $statusCode, $errorBody));
			return sprintf('Failed to start K8s ExApp "%s" (Status: %d). Details: %s', $logName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while starting K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to start K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while starting K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error while starting K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	public function stopExApp(string $harpUrl, string $exAppName, bool $ignoreIfAlready = false, string $roleSuffix = ''): string {
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/stop', $harpUrl),
				['json' => $this->buildNamePayload($exAppName, '', $roleSuffix)]
			);

			$statusCode = $response->getStatusCode();
			$logName = $this->logName($exAppName, $roleSuffix);
			if ($statusCode === 204) {
				$this->logger->info(sprintf('K8s ExApp "%s" successfully stopped (scaled to 0 replicas).', $logName));
				return '';
			}
			if ($statusCode === 200) {
				if ($ignoreIfAlready) {
					$this->logger->info(sprintf('K8s ExApp "%s" was already stopped.', $logName));
					return '';
				} else {
					$errorMsg = sprintf('K8s ExApp "%s" was already stopped.', $logName);
					$this->logger->warning($errorMsg);
					return $errorMsg;
				}
			}

			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to stop K8s ExApp "%s". Status: %d, Body: %s', $logName, $statusCode, $errorBody));
			return sprintf('Failed to stop K8s ExApp "%s" (Status: %d). Details: %s', $logName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while stopping K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to stop K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while stopping K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error while stopping K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	private function installCertificates(string $harpUrl, string $exAppName, string $instanceId, string $roleSuffix = ''): void {
		try {
			$logName = $this->logName($exAppName, $roleSuffix);
			$this->logger->info(sprintf('Starting certificate installation process for K8s ExApp "%s".', $logName));

			$payload = $this->buildNamePayload($exAppName, $instanceId, $roleSuffix);
			$payload['system_certs_bundle'] = null;
			$payload['install_frp_certs'] = false;

			$bundlePath = $this->certificateManager->getAbsoluteBundlePath();
			if (file_exists($bundlePath) && is_readable($bundlePath)) {
				$payload['system_certs_bundle'] = file_get_contents($bundlePath);
				if ($payload['system_certs_bundle'] === false) {
					$this->logger->warning(sprintf('Failed to read system CA bundle from "%s" for K8s ExApp "%s".', $bundlePath, $logName));
					$payload['system_certs_bundle'] = null;
				}
			} else {
				$this->logger->warning(sprintf('System CA bundle not found or not readable at "%s" for K8s ExApp "%s".', $bundlePath, $logName));
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
				$this->logger->info(sprintf('Certificate installation completed for K8s ExApp "%s".', $logName));
			} else {
				$errorBody = (string)$response->getBody();
				$this->logger->warning(sprintf('Certificate installation for K8s ExApp "%s" returned status %d: %s', $logName, $statusCode, $errorBody));
			}
		} catch (GuzzleException $e) {
			$this->logger->warning(sprintf('GuzzleException during certificate installation for K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
		} catch (Exception $e) {
			$this->logger->warning(sprintf('Exception during certificate installation for K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
		}
	}

	public function waitExAppStart(string $harpUrl, string $exAppName, string $roleSuffix = ''): bool {
		try {
			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/wait_for_start', $harpUrl),
				[
					'json' => $this->buildNamePayload($exAppName, '', $roleSuffix),
					'timeout' => 3700,
				]
			);

			$logName = $this->logName($exAppName, $roleSuffix);
			$statusCode = $response->getStatusCode();
			if ($statusCode === 200) {
				$responseData = json_decode((string)$response->getBody(), true);
				if ($responseData === null) {
					$this->logger->error(sprintf('Invalid JSON response from HaRP /k8s/exapp/wait_for_start for ExApp "%s".', $logName));
					return false;
				}

				$started = $responseData['started'] ?? false;
				$status = $responseData['status'] ?? 'unknown';
				$reason = $responseData['reason'] ?? '';

				if ($started === true) {
					$this->logger->info(sprintf('K8s Pod for ExApp "%s" is ready. Status: %s', $logName, $status));
					return true;
				} else {
					$this->logger->warning(sprintf('K8s Pod for ExApp "%s" did not become ready. Status: %s, Reason: %s', $logName, $status, $reason));
					return false;
				}
			} else {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to wait for K8s ExApp "%s" start. Status: %d, Body: %s', $logName, $statusCode, $errorBody));
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

	public function removeExApp(string $harpUrl, string $exAppName, bool $removeData = false, string $roleSuffix = ''): string {
		try {
			$error = $this->checkExists($harpUrl, $exAppName, '', $exists, $roleSuffix);
			if ($error) {
				return $error;
			}

			$logName = $this->logName($exAppName, $roleSuffix);
			if (!$exists) {
				$this->logger->info(sprintf('K8s ExApp "%s" does not exist. No removal needed.', $logName));
				return '';
			}

			$payload = $this->buildNamePayload($exAppName, '', $roleSuffix);
			$payload['remove_data'] = $removeData;

			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/remove', $harpUrl),
				['json' => $payload]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode === 204) {
				$this->logger->info(sprintf('K8s ExApp "%s" successfully removed.', $logName));
				return '';
			}

			$errorBody = (string)$response->getBody();
			$this->logger->error(sprintf('Failed to remove K8s ExApp "%s". Status: %d, Body: %s', $logName, $statusCode, $errorBody));
			return sprintf('Failed to remove K8s ExApp "%s" (Status: %d). Details: %s', $logName, $statusCode, $errorBody);
		} catch (GuzzleException $e) {
			$this->logger->error(sprintf('GuzzleException while removing K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Failed to communicate with HaRP to remove K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		} catch (Exception $e) {
			$this->logger->error(sprintf('Exception while removing K8s ExApp "%s": %s', $exAppName, $e->getMessage()), ['exception' => $e]);
			return sprintf('Unexpected error while removing K8s ExApp "%s": %s', $exAppName, $e->getMessage());
		}
	}

	public function exposeExApp(string $harpUrl, string $exAppName, int $port, array $k8sConfig, string $roleSuffix = ''): array {
		try {
			$exposePayload = $this->buildNamePayload($exAppName, '', $roleSuffix);
			$exposePayload['port'] = $port;
			$exposePayload['expose_type'] = $k8sConfig['expose_type'] ?? 'clusterip';
			$exposePayload['upstream_host'] = $k8sConfig['upstream_host'] ?? null;
			$exposePayload['node_port'] = $k8sConfig['node_port'] ?? null;
			$exposePayload['external_traffic_policy'] = $k8sConfig['external_traffic_policy'] ?? null;
			$exposePayload['load_balancer_ip'] = $k8sConfig['load_balancer_ip'] ?? null;
			$exposePayload['node_address_type'] = $k8sConfig['node_address_type'] ?? 'InternalIP';

			$logName = $this->logName($exAppName, $roleSuffix);
			$this->logger->debug(sprintf('Payload for /k8s/exapp/expose for %s: %s', $logName, json_encode($exposePayload)));

			$response = $this->guzzleClient->post(
				sprintf('%s/exapp/expose', $harpUrl),
				['json' => $exposePayload]
			);

			$statusCode = $response->getStatusCode();
			if ($statusCode !== 200) {
				$errorBody = (string)$response->getBody();
				$this->logger->error(sprintf('Failed to expose K8s ExApp "%s". Status: %d, Body: %s', $logName, $statusCode, $errorBody));
				return ['error' => sprintf('Failed to expose K8s ExApp "%s" (Status: %d). Details: %s', $logName, $statusCode, $errorBody)];
			}

			$responseData = json_decode((string)$response->getBody(), true);
			if ($responseData === null) {
				$this->logger->error(sprintf('Invalid JSON response from HaRP /k8s/exapp/expose for ExApp "%s".', $logName));
				return ['error' => sprintf('Invalid JSON response from HaRP /k8s/exapp/expose for ExApp "%s".', $logName)];
			}

			$this->logger->info(sprintf('K8s ExApp "%s" exposed successfully. Host: %s, Port: %d', $logName, $responseData['host'] ?? 'N/A', $responseData['port'] ?? 0));
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
			'net' => 'bridge',
			'env' => $envs,
			'computeDevice' => $deployConfig['computeDevice'] ?? null,
			'resourceLimits' => $deployConfig['resourceLimits'] ?? []
		];

		$result = [
			'image_params' => $imageParams,
			'container_params' => $containerParams,
			'deploy_options' => [
				'environment_variables' => $appInfo['external-app']['environment-variables'] ?? [],
				'mounts' => $appInfo['external-app']['mounts'] ?? [],
			]
		];

		$roles = self::getServiceRoles($appInfo);
		if (!empty($roles)) {
			$result['k8s_service_roles'] = $roles;
		}

		return $result;
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

		$computeDeviceId = $deployConfig['computeDevice']['id'] ?? 'cpu';
		$autoEnvs[] = sprintf('COMPUTE_DEVICE=%s', strtoupper($computeDeviceId));

		if (isset($deployConfig['computeDevice'])) {
			if ($deployConfig['computeDevice']['id'] === 'cuda') {
				$autoEnvs[] = sprintf('NVIDIA_VISIBLE_DEVICES=%s', 'all');
				$autoEnvs[] = sprintf('NVIDIA_DRIVER_CAPABILITIES=%s', 'compute,utility');
			}
		}

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
		$url = rtrim($deployConfig['nextcloud_url'], '/');
		if (str_ends_with($url, '/index.php')) {
			$url = substr($url, 0, -10);
		}
		return sprintf('%s/exapps/%s', $url, $appId);
	}

	public function buildHarpK8sUrl(DaemonConfig $daemonConfig): string {
		$url = $daemonConfig->getProtocol() . '://' . $daemonConfig->getHost();
		return rtrim($url, '/') . '/exapps/app_api/k8s';
	}

	private function buildImageName(array $imageParams): string {
		return $imageParams['image_src'] . '/' . $imageParams['image_name'] . ':' . $imageParams['image_tag'];
	}

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
