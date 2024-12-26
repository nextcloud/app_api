<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\UI;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Script
 *
 * @package OCA\AppAPI\Db\UI
 *
 * @method string getAppid()
 * @method string getType()
 * @method string getName()
 * @method string getPath()
 * @method string getAfterAppId()
 * @method void setAppid(string $appid)
 * @method void setType(string $type)
 * @method void setName(string $name)
 * @method void setPath(string $path)
 * @method void setAfterAppId(string $afterAppId)
 */
class Script extends Entity implements JsonSerializable {
	protected $appid;
	protected $type;
	protected $name;
	protected $path;
	protected $afterAppId;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('type', 'string');
		$this->addType('name', 'string');
		$this->addType('path', 'string');
		$this->addType('afterAppId', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['type'])) {
			$this->setType($params['type']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
		if (isset($params['path'])) {
			$this->setPath($params['path']);
		}
		if (isset($params['after_app_id'])) {
			$this->setAfterAppId($params['after_app_id']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'type' => $this->getType(),
			'name' => $this->getName(),
			'path' => $this->getPath(),
			'after_app_id' => $this->getAfterAppId(),
		];
	}
}
