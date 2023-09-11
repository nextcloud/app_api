<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExAppApiScope>
 */
class ExAppApiScopeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_api_scopes');
	}

	/**
	 * @throws Exception
	 */
	public function findAll(int $limit = null, int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/**
	 * @param string $apiRoute
	 *
	 * @throws Exception
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 *
	 * @return ExAppApiScope|null
	 */
	public function findByApiRoute(string $apiRoute): ?ExAppApiScope {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('api_route', $qb->createNamedParameter($apiRoute)));
		return $this->findEntity($qb);
	}
}
