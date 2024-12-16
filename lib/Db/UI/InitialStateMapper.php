<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\UI;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<InitialState>
 */
class InitialStateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_ui_states');
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
		$result = $qb->select('key', 'value')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			)->executeQuery();
		return $result->fetchAll();
	}

	/**
	 * @param string $appId
	 * @param string $type
	 * @param string $name
	 * @param string $key
	 *
	 * @return InitialState
	 * @throws Exception
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByAppIdTypeNameKey(string $appId, string $type, string $name, string $key): InitialState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR))
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

	/**
	 * @throws Exception
	 */
	public function removeAllByTypeName(string $appId, string $type, string $name): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
			);
		return $qb->executeStatement();
	}

	public function removeByKey(string $appId, string $type, string $name, string $key): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR))
			);
		try {
			$result = $qb->executeStatement();
			if ($result) {
				return true;
			}
		} catch (Exception) {
		}
		return false;
	}
}
