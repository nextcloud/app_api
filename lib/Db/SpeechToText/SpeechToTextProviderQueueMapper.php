<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\SpeechToText;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SpeechToTextProviderQueue>
 */
class SpeechToTextProviderQueueMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ex_speech_to_text_q');
	}

	/**
	 * @param int $id
	 *
	 * @throws DoesNotExistException if not found
	 * @throws MultipleObjectsReturnedException if more than one result
	 * @throws Exception
	 *
	 * @return SpeechToTextProviderQueue
	 */
	public function getById(int $id): SpeechToTextProviderQueue {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);
		return $this->findEntity($qb);
	}

	/**
	 * @throws Exception
	 */
	public function removeAllOlderThenThat(int $overdueTime): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->gte($qb->createNamedParameter(time() - $overdueTime, IQueryBuilder::PARAM_INT), 'created_time')
			);
		return $qb->executeStatement();
	}
}
