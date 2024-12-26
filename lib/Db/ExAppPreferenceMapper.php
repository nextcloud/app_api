<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExAppPreference>
 */
class ExAppPreferenceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'preferences_ex');
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
