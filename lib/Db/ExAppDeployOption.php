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
 * Class ExAppDeployOption
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getType()
 * @method array getValue()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setType(string $type)
 * @method void setValue(array $value)
 */
class ExAppDeployOption extends Entity implements JsonSerializable {
	protected $appid;
	protected $type;
	protected $value;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('type', 'string');
		$this->addType('value', 'json');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['type'])) {
			$this->setType($params['type']);
		}
		if (isset($params['value'])) {
			$this->setValue($params['value']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'type' => $this->getType(),
			'value' => $this->getValue(),
		];
	}
}
