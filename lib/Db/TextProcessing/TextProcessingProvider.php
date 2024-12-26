<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\TextProcessing;

use OCP\AppFramework\Db\Entity;

/**
 * Class TextProcessingProvider
 *
 * @package OCA\AppAPI\Db\TextProcessing
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getActionHandler()
 * @method string getTaskType()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setActionHandler(string $actionHandler)
 * @method void setTaskType(string $taskType)
 */
class TextProcessingProvider extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $actionHandler;
	protected $taskType;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('actionHandler', 'string');
		$this->addType('taskType', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
		if (isset($params['display_name'])) {
			$this->setDisplayName($params['display_name']);
		}
		if (isset($params['action_handler'])) {
			$this->setActionHandler($params['action_handler']);
		}
		if (isset($params['task_type'])) {
			$this->setTaskType($params['task_type']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'action_handler' => $this->getActionHandler(),
			'task_type' => $this->getTaskType(),
		];
	}
}
