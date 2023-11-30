<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TopMenu>
 */
class StyleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_ui_styles');
	}

	/**
	 * @param string $appId
	 * @param string $type
	 * @param string $name
	 * @return array
	 * @throws Exception
	 */
	public function findByAppIdTypeName(string $appId, string $type, string $name): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('path')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			)->executeQuery();
		return $result->fetchAll();
	}
}
