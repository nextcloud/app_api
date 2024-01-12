<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\MachineTranslation;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MachineTranslationProvider>
 */
class MachineTranslationProviderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_translation');
	}

	/**
	 * @throws Exception
	 */
	public function findAllEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select(
			'ex_translation.appid',
			'ex_translation.name',
			'ex_translation.display_name',
			'ex_translation.from_languages',
			'ex_translation.from_languages_labels',
			'ex_translation.to_languages',
			'ex_translation.to_languages_labels',
			'ex_translation.action_handler',
		)
			->from($this->tableName, 'ex_machine_translation')
			->innerJoin('ex_translation', 'ex_apps', 'exa', 'exa.appid = ex_translation.appid')
			->where(
				$qb->expr()->eq('exa.enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			)
			->executeQuery();
		return $result->fetchAll();
	}

	/**
	 * @param string $appId
	 * @param string $name
	 *
	 * @return MachineTranslationProvider
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 *
	 * @throws DoesNotExistException
	 */
	public function findByAppidName(string $appId, string $name): MachineTranslationProvider {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntity($qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId), IQueryBuilder::PARAM_STR))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name), IQueryBuilder::PARAM_STR))
		);
	}

	/**
	 * @throws Exception
	 */
	public function removeAllByAppId(string $appId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))
			);
		return $qb->executeStatement();
	}
}
