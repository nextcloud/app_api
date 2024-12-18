<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppPreference
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getUserid()
 * @method string getAppid()
 * @method string getConfigkey()
 * @method string getConfigvalue()
 * @method void setUserid(string $userid)
 * @method void setAppid(string $appid)
 * @method void setConfigkey(string $configkey)
 * @method void setConfigvalue(string $configvalue)
 */
class ExAppPreference extends Entity implements JsonSerializable {
	protected $userid;
	protected $appid;
	protected $configkey;
	protected $configvalue;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('userid', 'string');
		$this->addType('appid', 'string');
		$this->addType('configkey', 'string');
		$this->addType('configvalue', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['userid'])) {
			$this->setUserid($params['userid']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['configkey'])) {
			$this->setConfigkey($params['configkey']);
		}
		if (isset($params['configvalue'])) {
			$this->setConfigvalue($params['configvalue']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'user_id' => $this->getUserid(),
			'appid' => $this->getAppid(),
			'configkey' => $this->getConfigkey(),
			'configvalue' => $this->getConfigvalue(),
		];
	}
}
