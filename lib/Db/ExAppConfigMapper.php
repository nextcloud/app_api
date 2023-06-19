<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ExAppConfigMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'appconfig_ex');
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
	 *
	 * @return ExAppConfig[]
	 */
	public function findAllByAppId(string $appId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))
			);
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
	public function updateAppConfigValue(ExAppConfig $appConfigEx): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->tableName)
			->set('configvalue', $qb->createNamedParameter($appConfigEx->getConfigvalue(), IQueryBuilder::PARAM_STR))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appConfigEx->getAppid(), IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('configkey', $qb->createNamedParameter($appConfigEx->getConfigkey(), IQueryBuilder::PARAM_STR))
			)
		->executeStatement();
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

	/**
	 * @throws Exception
	 */
	public function deleteAllByAppId(string $appId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))
			)
		->executeStatement();
	}
}
