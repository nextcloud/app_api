<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MenuEntry>
 */
class InitialStateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_ui_state');
	}

	/**
	 * @param string $appId
	 * @param string $type
	 *
	 * @throws Exception
	 * @return array
	 */
	public function findByAppIdType(string $appId, string $type): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('key', 'value')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR))
			)->executeQuery();
			;
		return $result->fetchAll();
	}
}
