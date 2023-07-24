<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExAppScope>
 */
class ExAppScopeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_scopes');
	}

	/**
	 * @param string $appId
	 *
	 * @throws Exception
	 * @return ExAppScope[]
	 */
	public function findByAppid(string $appId): array {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntities($qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId), IQueryBuilder::PARAM_STR))
		);
	}

	/**
	 * @param string $appId
	 * @param int $scopeGroup
	 *
	 * @throws DoesNotExistException if not found
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException if more than one result
	 * @return ExAppScope|null
	 */
	public function findByAppidScope(string $appId, int $scopeGroup): ?ExAppScope {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntity($qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId), IQueryBuilder::PARAM_STR))
			->andWhere($qb->expr()->eq('scope_group', $qb->createNamedParameter($scopeGroup), IQueryBuilder::PARAM_INT))
		);
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
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId), IQueryBuilder::PARAM_STR))
			->executeStatement();
	}
}
