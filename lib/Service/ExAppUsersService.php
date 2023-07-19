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
use OCA\AppEcosystemV2\Db\ExAppUser;
use OCA\AppEcosystemV2\Db\ExAppUserMapper;

use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExAppUsersService {
	public const CACHE_TLL = 60 * 60; // 1 hour
	private LoggerInterface $logger;
	private ICache $cache;
	private ExAppUserMapper $mapper;

	public function __construct(
		LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		ExAppUserMapper $mapper,
	) {
		$this->logger = $logger;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_users');
		$this->mapper = $mapper;
	}

	/**
	 * @param ExApp $exApp
	 *
	 * @throws Exception
	 */
	public function setupSystemAppFlag(ExApp $exApp): void {
		$this->mapper->insert(new ExAppUser([
			'appid' => $exApp->getAppid(),
			'userid' => '',
		]));
	}

	/**
	 * @param ExApp $exApp
	 * @param string|null $userId
	 *
	 * @throws Exception
	 */
	public function setupExAppUser(ExApp $exApp, ?string $userId): void {
		if (!empty($userId)) {
			if (!$this->exAppUserExists($exApp->getAppid(), $userId)) {
				$this->mapper->insert(new ExAppUser([
					'appid' => $exApp->getAppid(),
					'userid' => $userId,
				]));
			}
		}
	}

	public function getExAppUsers(ExApp $exApp): array {
		try {
			$cacheKey = '/ex_apps_users_' . $exApp->getAppid();
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				array_map(function ($cashedEntry) {
					return $cashedEntry instanceof ExAppUser ? $cashedEntry : new ExAppUser($cashedEntry);
				}, $cached);
			}

			$exAppUser = $this->mapper->findByAppid($exApp->getAppid());
			$this->cache->set($cacheKey, $exAppUser, self::CACHE_TLL);
			return $exAppUser;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to get ex_app_users for ExApp %s. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return [];
		}
	}

	public function removeExAppUsers(ExApp $exApp): bool {
		try {
			$result = $this->mapper->deleteByAppid($exApp->getAppid()) !== 0;
			if ($result) {
				$this->cache->remove('/ex_apps_users_' . $exApp->getAppid());
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to remove ex_app_users for appid %s. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	public function exAppUserExists(string $appId, string $userId): bool {
		try {
			$cacheKey = '/ex_apps_users_' . $appId . '_' . $userId;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				$exAppUsers = array_map(function ($cashedEntry) {
					return $cashedEntry instanceof ExAppUser ? $cashedEntry : new ExAppUser($cashedEntry);
				}, $cached);
				return !empty($exAppUsers) && $exAppUsers[0] instanceof ExAppUser;
			}

			$exAppUsers = $this->mapper->findByAppidUserid($appId, $userId);
			if (!empty($exAppUsers) && $exAppUsers[0] instanceof ExAppUser) {
				$this->cache->set($cacheKey, $exAppUsers, self::CACHE_TLL);
				return true;
			}
		} catch (Exception) {
			return false;
		}
		return false;
	}
}
