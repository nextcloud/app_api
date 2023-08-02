<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExApp>
 */
class ExAppMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps');
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
	 * @param string $appId
	 *
	 * @throws DoesNotExistException if not found
	 * @throws MultipleObjectsReturnedException if more than one result
	 * @throws Exception
	 *
	 * @return ExApp
	 */
	public function findByAppId(string $appId): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId))
			);
		return $this->findEntity($qb);
	}

	/**
	 * @param int $port
	 *
	 * @throws Exception
	 *
	 * @return array
	 */
	public function findByPort(int $port): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('port', $qb->createNamedParameter($port))
			);
		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function deleteExApp(ExApp $exApp): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($exApp->getAppid(), IQueryBuilder::PARAM_STR))
			)->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function updateExAppEnabled(string $appId, bool $enabled): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->tableName)
			->set('enabled', $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))
			)->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function updateLastCheckTime(ExApp $exApp): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->tableName)
			->set('last_check_time', $qb->createNamedParameter($exApp->getLastCheckTime(), IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($exApp->getAppid()))
			)->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function updateExAppVersion(ExApp $exApp): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->tableName)
			->set('version', $qb->createNamedParameter($exApp->getVersion(), IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($exApp->getAppid()))
			)->executeStatement();
	}
}
