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
	public const CACHE_TTL = 60 * 60; // 1 hour
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
			$this->cache->set($cacheKey, $exAppScopes, self::CACHE_TTL);
			return $exAppScopes;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to get all api scopes. Error: %s', $e->getMessage()), ['exception' => $e]);
			return [];
		}
	}

	public function setExAppScopeGroup(ExApp $exApp, int $scopeGroup) {
		try {
			return $this->mapper->findByAppidScope($exApp->getAppid(), $scopeGroup);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exAppScope = new ExAppScope([
				'appid' => $exApp->getAppid(),
				'scope_group' => $scopeGroup,
			]);
			try {
				return $this->mapper->insert($exAppScope);
			} catch (\Exception $e) {
				$this->logger->error(sprintf('Error while setting ExApp scope group: %s', $e->getMessage()));
				return null;
			}
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
			$this->cache->set($cacheKey, $exAppScope, self::CACHE_TTL);
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
			$result = $this->mapper->deleteByAppid($exApp->getAppid());
			return $result > 0;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to delete all ExApp %s scopes. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}
}
