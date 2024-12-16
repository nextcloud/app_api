<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppEventsListener
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getEventType()
 * @method array getEventSubtypes()
 * @method string getActionHandler()
 * @method void setAppid(string $appid)
 * @method void setEventType(string $eventType)
 * @method void setEventSubtypes(array $eventSubtypes)
 * @method void setActionHandler(string $actionHandler)
 */
class ExAppEventsListener extends Entity implements JsonSerializable {
	protected $appid;
	protected $eventType;
	protected $eventSubtypes;
	protected $icon;
	protected $actionHandler;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('eventType', 'string');
		$this->addType('eventSubtypes', 'json');
		$this->addType('actionHandler', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['event_type'])) {
			$this->setEventType($params['event_type']);
		}
		if (isset($params['event_subtypes'])) {
			$this->setEventSubtypes($params['event_subtypes']);
		}
		if (isset($params['action_handler'])) {
			$this->setActionHandler($params['action_handler']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'event_type' => $this->getEventType(),
			'event_subtypes' => $this->getEventSubtypes(),
			'action_handler' => $this->getActionHandler(),
		];
	}
}
