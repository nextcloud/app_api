<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\MenuEntry;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MenuEntry>
 */
class MenuEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'menu_entries_ex');
	}

	/**
	 * @throws Exception
	 */
	public function findAllEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select(
			'menu_entries.appid',
			'menu_entries.name',
			'menu_entries.display_name',
			'menu_entries.route',
			'menu_entries.icon_url',
			'menu_entries.admin_required',
		)
			->from($this->tableName, 'menu_entries')
			->innerJoin('menu_entries', 'ex_apps', 'exa', 'exa.appid = menu_entries.appid')
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
	 * @throws DoesNotExistException if not found
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException if more than one result
	 * @return MenuEntry
	 */
	public function findByAppidName(string $appId, string $name): MenuEntry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			);
		return $this->findEntity($qb);
	}
}