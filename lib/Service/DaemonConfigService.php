<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\DaemonConfig;
use OCA\AppAPI\Db\DaemonConfigMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Daemon configuration (daemons)
 */
class DaemonConfigService {
	public const CACHE_TTL = 60 * 60 * 2; // 2 hours
	private LoggerInterface $logger;
	private ICache $cache;
	private DaemonConfigMapper $mapper;

	public function __construct(
		LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		DaemonConfigMapper $mapper,
	) {
		$this->logger = $logger;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/daemon_configs');
		$this->mapper = $mapper;
	}

	public function registerDaemonConfig(array $params): ?DaemonConfig {
		try {
			$daemonConfig = $this->mapper->insert(new DaemonConfig([
				'name' => $params['name'],
				'display_name' => $params['display_name'],
				'accepts_deploy_id' => $params['accepts_deploy_id'],
				'protocol' => $params['protocol'],
				'host' => $params['host'],
				'deploy_config' => $params['deploy_config'],
			]));
			$this->cache->remove('/daemon_configs');
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to register daemon config. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function unregisterDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			$daemonConfig = $this->mapper->delete($daemonConfig);
			$this->cache->remove('/daemon_configs');
			$this->cache->remove('/daemon_config_' . $daemonConfig->getName());
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to unregister daemon config. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * @return DaemonConfig[]|null
	 */
	public function getRegisteredDaemonConfigs(): ?array {
		try {
			$cacheKey = '/daemon_configs';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function ($cachedEntry) {
					return $cachedEntry instanceof DaemonConfig ? $cachedEntry : new DaemonConfig($cachedEntry);
				}, $cached);
			}

			$daemonConfigs = $this->mapper->findAll();
			$this->cache->set($cacheKey, $daemonConfigs, self::CACHE_TTL);
			return $daemonConfigs;
		} catch (Exception $e) {
			$this->logger->error('Failed to get registered daemon configs. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function getDaemonConfigByName(string $name): ?DaemonConfig {
		try {
			$cacheKey = '/daemon_config_' . $name;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached instanceof DaemonConfig ? $cached : new DaemonConfig($cached);
			}

			$daemonConfig = $this->mapper->findByName($name);
			$this->cache->set($cacheKey, $daemonConfig, self::CACHE_TTL);
			return $daemonConfig;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error('Failed to get daemon config by name. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	public function updateDaemonConfig(DaemonConfig $daemonConfig): ?DaemonConfig {
		try {
			$cacheKey = '/daemon_config_' . $daemonConfig->getName();
			$daemonConfig = $this->mapper->update($daemonConfig);
			$this->cache->set($cacheKey, $daemonConfig, self::CACHE_TTL);
			return $daemonConfig;
		} catch (Exception $e) {
			$this->logger->error('Failed to update DaemonConfig. Error: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}
}
