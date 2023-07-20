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
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExApp>
 */
class ExAppMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps');
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
	 * @throws DoesNotExistException if not found
	 * @throws MultipleObjectsReturnedException if more than one result
	 * @throws Exception
	 *
	 * @return ExApp
	 */
	public function findByAppId(string $appId): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId))
			);
		return $this->findEntity($qb);
	}

	/**
	 * @param int $port
	 *
	 * @throws Exception
	 *
	 * @return array
	 */
	public function findByPort(int $port): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('port', $qb->createNamedParameter($port))
			);
		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function deleteExApp(ExApp $exApp): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($exApp->getAppid(), IQueryBuilder::PARAM_STR))
			)->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function updateExAppEnabled(string $appId, bool $enabled): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->tableName)
			->set('enabled', $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR))
			)->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function updateLastCheckTime(ExApp $exApp): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update($this->tableName)
			->set('last_check_time', $qb->createNamedParameter($exApp->getLastCheckTime(), IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter($exApp->getAppid()))
			)->executeStatement();
	}
}
