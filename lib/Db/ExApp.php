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

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExApp
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getAppid()
 * @method string getVersion()
 * @method string getName()
 * @method string getConfig()
 * @method string getSecret()
 * @method string getStatus()
 * @method int getEnabled()
 * @method string getCreatedTime()
 * @method string getLastResponseTime()
 * @method void setAppid(string $appid)
 * @method void setVersion(string $version)
 * @method void setName(string $name)
 * @method void setConfig(string $config)
 * @method void setSecret(string $secret)
 * @method void setStatus(string $status)
 * @method void setEnabled(int $enabled)
 * @method void setCreatedTime(string $createdTime)
 * @method void setLastResponseTime(string $lastResponseTime)
 */
class ExApp extends Entity implements JsonSerializable {
	protected $appid;
	protected $version;
	protected $name;
	protected $config;
	protected $secret;
	protected $status;
	protected $enabled;
	protected $createdTime;
	protected $lastResponseTime;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['version'])) {
			$this->setVersion($params['version']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
		if (isset($params['config'])) {
			$this->setConfig($params['config']);
		}
		if (isset($params['secret'])) {
			$this->setSecret($params['secret']);
		}
		if (isset($params['status'])) {
			$this->setStatus($params['status']);
		}
		if (isset($params['enabled'])) {
			$this->setEnabled($params['enabled']);
		}
		if (isset($params['created_time'])) {
			$this->setCreatedTime($params['created_time']);
		}
		if (isset($params['last_response_time'])) {
			$this->setLastResponseTime($params['last_response_time']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'app_id' => $this->getAppid(),
			'version' => $this->getVersion(),
			'name'=> $this->getName(),
			'config' => $this->getConfig(),
			'secret' => $this->getSecret(),
			'status' => $this->getStatus(),
			'enabled' => $this->getEnabled(),
			'created_time' => $this->getCreatedTime(),
			'last_response_time' => $this->getLastResponseTime(),
		];
	}
}
