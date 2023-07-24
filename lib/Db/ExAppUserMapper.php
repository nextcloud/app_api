<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExAppUser>
 */
class ExAppUserMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_users');
	}

	/**
	 * @throws Exception
	 */
	public function findByAppid(string $appId): array {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntities($qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))));
	}

	/**
	 * @param string $appId
	 * @param string $userId
	 *
	 * @throws Exception
	 *
	 * @return ExAppUser[]
	 */
	public function findByAppidUserid(string $appId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntities($qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('userid', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orWhere(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('userid', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			));
	}

	/**
	 * @param string $appId
	 *
	 * @throws Exception
	 * @return int
	 */
	public function deleteByAppid(string $appId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)))
			->executeStatement();
	}
}
