<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppUser;
use OCA\AppAPI\Db\ExAppUserMapper;

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
				$this->cache->clear('/ex_apps_users_' . $exApp->getAppid());
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to remove ex_app_users for ExApp %s. Error: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return false;
		}
	}

	public function removeExAppUser(ExApp $exApp, string $userId): bool {
		try {
			$result = $this->mapper->deleteByAppid($exApp->getAppid()) !== 0;
			if ($result) {
				$this->cache->clear('/ex_apps_users_' . $exApp->getAppid());
			}
			return $result;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to remove ex_app_user %s for ExApp %s. Error: %s', $userId, $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
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
