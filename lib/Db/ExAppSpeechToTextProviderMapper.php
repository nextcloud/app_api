<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExAppSpeechToTextProvider>
 */
class ExAppSpeechToTextProviderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_speech_to_text');
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
	 * @throws Exception
	 * @return ExAppSpeechToTextProvider[]
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
	 * @param string $name
	 *
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 *
	 * @return ExAppSpeechToTextProvider
	 */
	public function findByAppidName(string $appId, string $name): ExAppSpeechToTextProvider {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntity($qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId), IQueryBuilder::PARAM_STR))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name), IQueryBuilder::PARAM_STR))
		);
	}
}
