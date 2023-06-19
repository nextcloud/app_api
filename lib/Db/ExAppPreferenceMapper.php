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
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ExAppPreferenceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'preferences_ex');
	}

	/**
	 * @throws Exception
	 * @return ExAppPreference[]
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
	 * @param string $userId
	 * @param string $appId
	 * @param string $configKey
	 *
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 *
	 * @return ExAppPreference|null
	 */
	public function findByUserIdAppIdKey(string $userId, string $appId, string $configKey): ?ExAppPreference {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('userid', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('configkey', $qb->createNamedParameter($configKey, IQueryBuilder::PARAM_STR))
			);
		return $this->findEntity($qb);
	}

	/**
	 * @param string $userId
	 * @param string $appId
	 * @param array $configKeys
	 *
	 * @throws Exception
	 *
	 * @return ExAppPreference[]
	 */
	public function findByUserIdAppIdKeys(string $userId, string $appId, array $configKeys): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('userid', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->in('configkey', $qb->createNamedParameter($configKeys, IQueryBuilder::PARAM_STR_ARRAY))
			);
		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function updateUserConfigValue(ExAppPreference $exAppPreference): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('configvalue', $qb->createNamedParameter($exAppPreference->getConfigvalue(), IQueryBuilder::PARAM_STR))
			->where(
				$qb->expr()->eq('userid', $qb->createNamedParameter($exAppPreference->getUserid(), IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('appid', $qb->createNamedParameter($exAppPreference->getAppid(), IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('configkey', $qb->createNamedParameter($exAppPreference->getConfigkey(), IQueryBuilder::PARAM_STR))
			);
		return $qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteUserConfigValues(array $configKeys, string $userId, string $appId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('userid', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->in('configkey', $qb->createNamedParameter($configKeys, IQueryBuilder::PARAM_STR_ARRAY), IQueryBuilder::PARAM_STR)
			);
		return $qb->executeStatement();
	}
}
