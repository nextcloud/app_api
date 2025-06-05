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
 * Class ExAppConfig
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getConfigkey()
 * @method string getConfigvalue()
 * @method int getSensitive()
 * @method void setAppid(string $appId)
 * @method void setConfigKey(string $configKey)
 * @method void setConfigvalue(string $configValue)
 * @method void setSensitive(int $sensitive)
 */
class ExAppConfig extends Entity implements JsonSerializable {
	protected $appid;
	protected $configkey;
	protected $configvalue;
	protected $sensitive;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('configkey', 'string');
		$this->addType('configvalue', 'string');
		$this->addType('sensitive', 'integer');

		if (isset($params['id'])) {
			$this->setId($params['id']);
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
		if (isset($params['sensitive'])) {
			$this->setSensitive($params['sensitive']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'configkey' => $this->getConfigkey(),
			'configvalue' => $this->getConfigvalue(),
			'sensitive' => $this->getSensitive(),
		];
	}
}
