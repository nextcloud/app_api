<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<FilesActionsMenu>
 */
class FilesActionsMenuMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_ui_files_actions');
	}

	/**
	 * @throws Exception
	 */
	public function findAllEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select(
			'ex_ui_files_actions.appid',
			'ex_ui_files_actions.name',
			'ex_ui_files_actions.display_name',
			'ex_ui_files_actions.mime',
			'ex_ui_files_actions.permissions',
			'ex_ui_files_actions.order',
			'ex_ui_files_actions.icon',
			'ex_ui_files_actions.action_handler',
		)
			->from($this->tableName, 'ex_ui_files_actions')
			->innerJoin('ex_ui_files_actions', 'ex_apps', 'exa', 'exa.appid = ex_ui_files_actions.appid')
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
	 * @return FilesActionsMenu
	 * @throws DoesNotExistException if not found
	 * @throws Exception
	 *
	 * @throws MultipleObjectsReturnedException if more than one result
	 */
	public function findByAppidName(string $appId, string $name): FilesActionsMenu {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)),
			);
		return $this->findEntity($qb);
	}

	/**
	 * @param string $name
	 *
	 *
	 * @return FilesActionsMenu
	 * @throws DoesNotExistException|Exception if not found
	 * @throws Exception
	 *
	 * @throws MultipleObjectsReturnedException if more than one result
	 */
	public function findByName(string $name): FilesActionsMenu {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			);
		return $this->findEntity($qb);
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
