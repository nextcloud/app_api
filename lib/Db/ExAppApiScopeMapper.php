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

use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;

class ExAppApiScopeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_apps_api_scopes');
	}

	public function findAll(int $limit = null, int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/**
	 * @param string $apiRoute
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException|Exception if more than one result
	 *
	 * @return ExAppApiScope
	 */
	public function findByApiRoute(string $apiRoute): Entity {
		$qb = $this->db->getQueryBuilder();
		return $this->findEntity($qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('api_route', $qb->createNamedParameter($apiRoute)))
		);
	}

	public function insert(Entity $entity): Entity {
		if (!$entity instanceof ExAppApiScope) {
			throw new \InvalidArgumentException('Wrong type of entity');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->tableName)
			->values([
				'api_route' => $qb->createNamedParameter($entity->getApiRoute()),
				'scope_group' => $qb->createNamedParameter($entity->getScopeGroup()),
			]);
		if ($qb->executeStatement() === 1) {
			return $entity;
		}
		throw new Exception('Could not insert entity');
	}

	public function update(Entity $entity): Entity {
		if (!$entity instanceof ExAppApiScope) {
			throw new \InvalidArgumentException('Wrong type of entity');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('scope_group', $qb->createNamedParameter($entity->getScopeGroup()))
			->where($qb->expr()->eq('api_route', $qb->createNamedParameter($entity->getApiRoute())));
		if ($qb->executeStatement() === 1) {
			return $entity;
		}
		throw new Exception('Could not update entity');
	}

	public function insertOrUpdate(Entity $entity): Entity {
		try {
			return $this->insert($entity);
		} catch (Exception $ex) {
			if ($ex->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return $this->update($entity);
			}
			throw $ex;
		}
	}
}
