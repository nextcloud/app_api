<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExAppUser;
use OCA\AppAPI\Db\ExAppUserMapper;

use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExAppUsersService {
	public const CACHE_TLL = 60 * 60; // 1 hour
	private ICache $cache;

	public function __construct(
		private readonly LoggerInterface $logger,
		ICacheFactory                    $cacheFactory,
		private readonly ExAppUserMapper $mapper,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_users');
	}

	/**
	 * @throws Exception
	 */
	public function setupExAppUser(string $appId, ?string $userId): void {
		if (!empty($userId)) {
			if (!$this->exAppUserExists($appId, $userId)) {
				$this->mapper->insert(new ExAppUser([
					'appid' => $appId,
					'userid' => $userId,
				]));
			}
		}
	}

	public function getExAppUsers(string $appId): array {
		try {
			$cacheKey = '/ex_apps_users_' . $appId;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				array_map(function ($cashedEntry) {
					return $cashedEntry instanceof ExAppUser ? $cashedEntry : new ExAppUser($cashedEntry);
				}, $cached);
			}

			$exAppUser = $this->mapper->findByAppid($appId);
			$this->cache->set($cacheKey, $exAppUser, self::CACHE_TLL);
			return $exAppUser;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to get ex_app_users for ExApp %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]);
			return [];
		}
	}

	public function removeExAppUsers(string $appId): bool {
		try {
			$result = $this->mapper->deleteByAppid($appId) !== 0;
			if ($result) {
				$this->cache->clear('/ex_apps_users_' . $appId);
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to remove ex_app_users for ExApp %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	public function removeExAppUser(string $appId, string $userId): bool {
		try {
			$result = $this->mapper->deleteByAppid($appId) !== 0;
			if ($result) {
				$this->cache->clear('/ex_apps_users_' . $appId);
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to remove ex_app_user %s for ExApp %s. Error: %s', $userId, $appId, $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	public function removeDeletedUser(string $userId): bool {
		try {
			$result = $this->mapper->deleteByUserId($userId) !== 0;
			if ($result) {
				$this->cache->clear('/ex_apps_users_');
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to remove ex_app_user %s after User deletion. Error: %s', $userId, $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * @throws Exception
	 */
	public function exAppUserExists(string $appId, string $userId): bool {
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
		return false;
	}
}
