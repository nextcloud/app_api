<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExAppConfig>
 */
class ExAppConfigMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'appconfig_ex');
	}

	/**
	 * @throws Exception
	 */
	public function findAllByAppid(string $appId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)));
		return $this->findEntities($qb);
	}

	/**
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @throws DoesNotExistException if not found
	 * @throws MultipleObjectsReturnedException if more than one result
	 * @throws Exception
	 *
	 * @return ExAppConfig
	 */
	public function findByAppConfigKey(string $appId, string $configKey): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('configkey', $qb->createNamedParameter($configKey, IQueryBuilder::PARAM_STR))
			);
		return $this->findEntity($qb);
	}

	/**
	 * @param string $appId
	 * @param array $configKeys
	 *
	 * @throws Exception
	 *
	 * @return ExAppConfig[]
	 */
	public function findByAppConfigKeys(string $appId, array $configKeys): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->in('configkey', $qb->createNamedParameter($configKeys, IQueryBuilder::PARAM_STR_ARRAY), IQueryBuilder::PARAM_STR)
			);
		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function deleteByAppidConfigkeys(string $appId, array $configKeys): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->in('configkey', $qb->createNamedParameter($configKeys, IQueryBuilder::PARAM_STR_ARRAY), IQueryBuilder::PARAM_STR)
			)
		->executeStatement();
	}
}
