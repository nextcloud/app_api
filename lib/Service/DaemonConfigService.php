<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\DaemonConfigMapper;

use OCA\AppAPI\DeployActions\ManualActions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Daemon configuration (daemons)
 */
class DaemonConfigService {
	public function __construct(
		private readonly LoggerInterface    $logger,
		private readonly DaemonConfigMapper $mapper,
		private readonly ExAppService       $exAppService,
		private readonly ICrypto			$crypto,
	) {
	}

	public function registerDaemonConfig(array $params): ?DaemonConfig {
		if (!isset($params['deploy_config']['net'])) {
			$this->logger->error('Failed to register daemon configuration: `net` key should be present in the deploy config.');
			return null;
		}
		$bad_patterns = ['http', 'https', 'tcp', 'udp', 'ssh'];
		$docker_host = (string)$params['host'];
		$frp_host = (string)($params['harp']['frp_address'] ?? '');
		foreach ($bad_patterns as $bad_pattern) {
			if (str_starts_with($docker_host, $bad_pattern . '://')) {
				$this->logger->error('Failed to register daemon configuration. `host` must not include a protocol.');
				return null;
			}
			if (str_starts_with($frp_host, $bad_pattern . '://')) {
				$this->logger->error('Failed to register daemon configuration. FRP\'s address must not include a protocol.');
				return null;
			}
		}
		if ($params['protocol'] !== 'http' && $params['protocol'] !== 'https') {
			$this->logger->error('Failed to register daemon configuration: `protocol` must be `http` or `https`.');
			return null;
		}
		if ($params['accepts_deploy_id'] !== ManualActions::DEPLOY_ID && isset($params['deploy_config']['harp']['exapp_direct']) && $params['deploy_config']['harp']['exapp_direct'] === true) {
			if ($params['deploy_config']['net'] === 'host') {
				$this->logger->error('Failed to register daemon configuration: setting `net=host` in HaRP is not supported when communication with ExApps is done directly without FRP.');
				return null;
			}
		}
		$params['deploy_config']['nextcloud_url'] = rtrim($params['deploy_config']['nextcloud_url'], '/');
		try {
			if (isset($params['deploy_config']['haproxy_password']) && $params['deploy_config']['haproxy_password'] !== '') {
				$params['deploy_config']['haproxy_password'] = $this->crypto->encrypt($params['deploy_config']['haproxy_password']);
			}
			return $this->mapper->insert(new DaemonConfig([
				'name' => $params['name'],
				'display_name' => $params['display_name'],
				'accepts_deploy_id' => $params['accepts_deploy_id'],
				'protocol' => $params['protocol'],
				'host' => $params['host'],
				'deploy_config' => $params['deploy_config'],
			]));
		} catch (Exception $e) {
			$this->logger->error('Failed to register daemon config. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function unregisterDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			return $this->mapper->delete($daemonConfig);
		} catch (Exception $e) {
			$this->logger->error('Failed to unregister daemon config. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * @return DaemonConfig[]
	 */
	public function getRegisteredDaemonConfigs(): array {
		try {
			return $this->mapper->findAll();
		} catch (Exception $e) {
			$this->logger->debug('Failed to get registered daemon configs. Error: ' . $e->getMessage(), ['exception' => $e]);
			return [];
		}
	}

	public function getDaemonConfigsWithAppsCount(): array {
		$daemonsExAppsCount = array_reduce($this->exAppService->getExApps(), function (array $carry, $exApp) {
			if (!isset($carry[$exApp->getDaemonConfigName()])) {
				$carry[$exApp->getDaemonConfigName()] = 0;
			}
			$carry[$exApp->getDaemonConfigName()] += 1;
			return $carry;
		}, []);

		return array_map(function (DaemonConfig $daemonConfig) use ($daemonsExAppsCount) {
			$serializedConfig = $daemonConfig->jsonSerialize();

			// Check if "haproxy_password" exists in "deployConfig" and mask it
			if (!empty($serializedConfig['deploy_config']['haproxy_password'])) {
				$serializedConfig['deploy_config']['haproxy_password'] = 'dummySecret123';
			}

			return [
				...$serializedConfig,
				'exAppsCount' => $daemonsExAppsCount[$daemonConfig->getName()] ?? 0,
			];
		}, $this->getRegisteredDaemonConfigs());
	}

	public function getDaemonConfigByName(string $name): ?DaemonConfig {
		try {
			return $this->mapper->findByName($name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->debug('Failed to get daemon config by name. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function updateDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			return $this->mapper->update($daemonConfig);
		} catch (Exception $e) {
			$this->logger->error('Failed to update DaemonConfig. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function addDockerRegistry(DaemonConfig $daemonConfig, array $registryMap): DaemonConfig|array|null {
		try {
			$deployConfig = $daemonConfig->getDeployConfig();

			if (!isset($deployConfig['registries'])) {
				$deployConfig['registries'] = [];
			}

			$fromExists = false;
			foreach ($deployConfig['registries'] as $registry) {
				if ($registry['from'] === $registryMap['from']) {
					$fromExists = true;
					break;
				}
			}
			if ($fromExists) {
				return ['error' => sprintf('This Docker registry map from "%s" already exists', $registryMap['from'])];
			}
			if ($registryMap['from'] === $registryMap['to']) {
				return ['error' => 'The source and target registry cannot be the same'];
			}
			if (empty($registryMap['from']) || empty($registryMap['to'])) {
				return ['error' => 'The source and target registry cannot be empty'];
			}

			$deployConfig['registries'][] = $registryMap;
			$daemonConfig->setDeployConfig($deployConfig);

			return $this->mapper->update($daemonConfig);
		} catch (Exception $e) {
			$this->logger->error('Failed to add registry to DaemonConfig. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function removeDockerRegistry(DaemonConfig $daemonConfig, array $registryMap): DaemonConfig|array|null {
		try {
			$deployConfig = $daemonConfig->getDeployConfig();

			if (!in_array($registryMap, $deployConfig['registries'])) {
				return ['error' => 'This Docker registry map does not exist'];
			}
			$deployConfig['registries'] = array_filter($deployConfig['registries'], function ($registry) use ($registryMap) {
				return !($registry['from'] === $registryMap['from'] && $registry['to'] === $registryMap['to']);
			});
			$daemonConfig->setDeployConfig($deployConfig);

			return $this->mapper->update($daemonConfig);
		} catch (Exception $e) {
			$this->logger->error('Failed to remove registry from DaemonConfig. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}
}
