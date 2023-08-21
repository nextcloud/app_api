<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppScope;
use OCA\AppEcosystemV2\Db\ExAppScopeMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExAppScopesService {
	private LoggerInterface $logger;
	private ExAppScopeMapper $mapper;
	private ICache $cache;

	public function __construct(
		LoggerInterface $logger,
		ExAppScopeMapper $mapper,
		ICacheFactory $cacheFactory,
	) {
		$this->logger = $logger;
		$this->mapper = $mapper;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_scopes');
	}

	public function getExAppScopes(ExApp $exApp): array {
		try {
			$cacheKey = '/ex_app_scopes_' . $exApp->getAppid();
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function ($cachedEntry) {
					return $cachedEntry instanceof ExAppScope ? $cachedEntry : new ExAppScope($cachedEntry);
				}, $cached);
			}

			$exAppScopes = $this->mapper->findByAppid($exApp->getAppid());
			$this->cache->set($cacheKey, $exAppScopes);
			return $exAppScopes;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to get all api scopes. Error: %s', $e->getMessage()), ['exception' => $e]);
			return [];
		}
	}

	public function setExAppScopeGroup(ExApp $exApp, int $scopeGroup): ?ExAppScope {
		$exAppScope = $this->getByScope($exApp, $scopeGroup);
		if ($exAppScope instanceof ExAppScope) {
			return $exAppScope;
		}

		$exAppScope = new ExAppScope([
			'appid' => $exApp->getAppid(),
			'scope_group' => $scopeGroup,
		]);
		try {
			return $this->mapper->insert($exAppScope);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while setting ExApp scope group: %s', $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	public function getByScope(ExApp $exApp, int $apiScope): ?ExAppScope {
		try {
			$cacheKey = '/ex_app_scopes_' . $exApp->getAppid() . '_' . $apiScope;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached instanceof ExAppScope ? $cached : new ExAppScope($cached);
			}

			$exAppScope = $this->mapper->findByAppidScope($exApp->getAppid(), $apiScope);
			$this->cache->set($cacheKey, $exAppScope);
			return $exAppScope;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function passesScopeCheck(ExApp $exApp, int $apiScope): bool {
		$exAppScope = $this->getByScope($exApp, $apiScope);
		return $exAppScope instanceof ExAppScope;
	}

	public function removeExAppScopes(ExApp $exApp): bool {
		try {
			$result = $this->mapper->deleteByAppid($exApp->getAppid()) > 0;
			if ($result) {
				$this->cache->clear('/ex_app_scopes_' . $exApp->getAppid());
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to delete all ExApp %s scopes. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	public function removeExAppScope(ExApp $exApp, int $apiScope): bool {
		$exAppScope = $this->getByScope($exApp, $apiScope);
		if ($exAppScope === null) {
			return false;
		}

		try {
			return $this->mapper->delete($exAppScope) instanceof ExAppScope;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to delete ExApp %s scope %s', $exApp->getAppid(), $apiScope), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Update ExApp scopes (setup new, remove old)
	 *
	 * @param ExApp $exApp
	 * @param array $newExAppScopes
	 *
	 * @return bool
	 */
	public function updateExAppScopes(ExApp $exApp, array $newExAppScopes): bool {
		$currentExAppScopes = array_map(function (ExAppScope $exAppScope) {
			return $exAppScope->getScopeGroup();
		}, $this->getExAppScopes($exApp));
		$newScopes = array_values(array_diff($newExAppScopes, $currentExAppScopes));
		$removedScopes = array_values(array_diff($currentExAppScopes, $newExAppScopes));

		foreach ($newScopes as $newScope) {
			if ($this->setExAppScopeGroup($exApp, $newScope) === null) {
				return false;
			}
		}

		foreach ($removedScopes as $removedScope) {
			if (!$this->removeExAppScope($exApp, $removedScope)) {
				return false;
			}
		}

		$this->cache->clear('/ex_app_scopes_' . $exApp->getAppid());
		return true;
	}
}
