<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\Translation;

use OCP\AppFramework\Db\Entity;

/**
 * Class TranslationQueue
 *
 * @package OCA\AppAPI\Db\Translation
 *
 * @method string getResult()
 * @method string getError()
 * @method int getFinished()
 * @method int getCreatedTime()
 * @method void setResult(string $result)
 * @method void setError(string $error)
 * @method void setFinished(int $finished)
 * @method void setCreatedTime(int $createdTime)
 */
class TranslationQueue extends Entity implements \JsonSerializable {
	protected $result;
	protected $error;
	protected $finished;
	protected $createdTime;

	public function __construct(array $params = []) {
		$this->addType('result', 'string');
		$this->addType('error', 'string');
		$this->addType('finished', 'int');
		$this->addType('createdTime', 'int');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['result'])) {
			$this->setResult($params['result']);
		}
		if (isset($params['error'])) {
			$this->setError($params['error']);
		}
		if (isset($params['finished'])) {
			$this->setFinished($params['finished']);
		}
		if (isset($params['created_time'])) {
			$this->setCreatedTime($params['created_time']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'result' => $this->getResult(),
			'error' => $this->getError(),
			'finished' => $this->getFinished(),
			'created_time' => $this->getCreatedTime(),
		];
	}
}
