<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class TalkBot
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getRoute()
 * @method string getSecret()
 * @method int getCreatedTime()
 * @method void setAppid(string $appid)
 * @method void setRoute(string $route)
 * @method void setSecret(string $secret)
 * @method void setCreatedTime(int $createdTime)
 */
class TalkBot extends Entity implements JsonSerializable {
	protected $appid;
	protected $route;
	protected $secret;
	protected $createdTime;

	public function __construct() {
		$this->addType('appid', 'string');
		$this->addType('route', 'string');
		$this->addType('secret', 'string');
		$this->addType('createdTime', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'route' => $this->getRoute(),
			'created_time' => $this->getCreatedTime(),
		];
	}
}
