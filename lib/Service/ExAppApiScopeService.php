<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExAppApiScope;
use OCA\AppEcosystemV2\Db\ExAppApiScopeMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExAppApiScopeService {
	private LoggerInterface $logger;
	private ExAppApiScopeMapper $mapper;
	private ICache $cache;

	public function __construct(
		LoggerInterface $logger,
		ExAppApiScopeMapper $mapper,
		ICacheFactory $cacheFactory,
	) {
		$this->logger = $logger;
		$this->mapper = $mapper;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_api_scopes');
	}

	public function getExAppApiScopes(): array {
		try {
			$cacheKey = '/all_api_scopes';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function ($cachedEntry) {
					return $cachedEntry instanceof ExAppApiScope ? $cachedEntry : new ExAppApiScope($cachedEntry);
				}, $cached);
			}

			$apiScopes = $this->mapper->findAll();
			$this->cache->set($cacheKey, $apiScopes);
			return $apiScopes;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to get all ApiScopes. Error: %s', $e->getMessage()), ['exception' => $e]);
			return [];
		}
	}

	public function getApiScopeByRoute(string $apiRoute): ?ExAppApiScope {
		try {
			$cacheKey = '/api_scope_' . $apiRoute;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached instanceof ExAppApiScope ? $cached : new ExAppApiScope($cached);
			}

			$apiScopes = $this->getExAppApiScopes();
			foreach ($apiScopes as $apiScope) {
				if (str_starts_with($apiRoute, $apiScope->getApiRoute())) {
					$this->cache->set($cacheKey, $apiScope);
					return $apiScope;
				}
			}
			return null;
		} catch (Exception) {
			return null;
		}
	}

	public function registerInitScopes(): bool {
		$aeApiV1Prefix = '/apps/' . Application::APP_ID . '/api/v1';

		$initApiScopes = [
			// AppEcosystemV2 scopes
			['api_route' => $aeApiV1Prefix . '/files/actions/menu', 'scope_group' => 1, 'name' => 'BASIC'],
			['api_route' => $aeApiV1Prefix . '/log', 'scope_group' => 1, 'name' => 'BASIC'],
			['api_route' => $aeApiV1Prefix . '/ex-app/config', 'scope_group' => 1, 'name' => 'BASIC'],
			['api_route' => $aeApiV1Prefix . '/ex-app/preference', 'scope_group' => 1, 'name' => 'BASIC'],
			['api_route' => $aeApiV1Prefix . '/users', 'scope_group' => 2, 'name' => 'SYSTEM'],
			['api_route' => $aeApiV1Prefix . '/ex-app/all', 'scope_group' => 2, 'name' => 'SYSTEM'],

			// Cloud scopes
			['api_route' => '/cloud/capabilities', 'scope_group' => 1, 'name' => 'BASIC'],
			['api_route' => '/cloud/apps', 'scope_group' => 2, 'name' => 'SYSTEM'],
			['api_route' => '/apps/provisioning_api/api/', 'scope_group' => 2, 'name' => 'SYSTEM'],
			['api_route' => '/cloud/users', 'scope_group' => 10, 'name' => 'USER_INFO'],
			['api_route' => '/cloud/groups', 'scope_group' => 10, 'name' => 'USER_INFO'],
			['api_route' => '/apps/user_status/api/', 'scope_group' => 11, 'name' => 'USER_STATUS'],
			['api_route' => '/apps/notifications/api/', 'scope_group' => 12, 'name' => 'NOTIFICATIONS'],
			['api_route' => '/apps/weather_status/api/', 'scope_group' => 13, 'name' => 'WEATHER_STATUS'],
			['api_route' => '/apps/files_sharing/api/', 'scope_group' => 14, 'name' => 'FILE_SHARING'],
			['api_route' => '/dav/', 'scope_group' => 3, 'name' => 'DAV'],
		];

		$this->cache->clear('/all_api_scopes');
		$registeredApiScopes = $this->getExAppApiScopes();
		$registeredApiScopesRoutes = [];
		foreach ($registeredApiScopes as $registeredApiScope) {
			$registeredApiScopesRoutes[$registeredApiScope->getApiRoute()] = $registeredApiScope->getId();
		}
		try {
			foreach ($initApiScopes as $apiScope) {
				if (in_array($apiScope['api_route'], array_keys($registeredApiScopesRoutes))) {
					$apiScope['id'] = $registeredApiScopesRoutes[$apiScope['api_route']];
				}
				$registeredApiScope = $this->mapper->insertOrUpdate(new ExAppApiScope($apiScope));
				$cacheKey = '/api_scope_' . $apiScope['api_route'];
				$this->cache->set($cacheKey, $registeredApiScope);
			}
			return true;
		} catch (Exception $e) {
			$this->logger->error('Failed to fill init ApiScopes: ' . $e->getMessage(), ['exception' => $e]);
			return false;
		}
	}

	public function registerApiScope(string $apiRoute, int $scopeGroup, string $name): ?ExAppApiScope {
		try {
			$apiScope = new ExAppApiScope([
				'api_route' => $apiRoute,
				'scope_group' => $scopeGroup,
				'name' => $name,
			]);
			try {
				$exAppApiScope = $this->mapper->findByApiRoute($apiRoute);
				if ($exAppApiScope !== null) {
					$apiScope->setId($exAppApiScope->getId());
				}
			} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
				$exAppApiScope = null;
			}
			$this->mapper->insertOrUpdate($apiScope);
			$this->cache->remove('/api_scope_' . $apiRoute);
			$this->cache->remove('/all_api_scopes');
			return $apiScope;
		} catch (Exception $e) {
			$this->logger->error('Failed to register API scope: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * @param int[] $scopeGroups
	 *
	 * @return string[]
	 */
	public function mapScopeGroupsToNames(array $scopeGroups): array {
		$apiScopes = array_filter($this->getExAppApiScopes(), function (ExAppApiScope $apiScope) use ($scopeGroups) {
			return in_array($apiScope->getScopeGroup(), $scopeGroups);
		});
		return array_map(function (ExAppApiScope $apiScope) {
			return $apiScope->getName();
		}, $apiScopes);
	}
}
