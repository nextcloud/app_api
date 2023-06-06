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
 * Class ExAppRequest
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getAppid()
 * @method string getUserid()
 * @method string getRequestToken()
 * @method string getExpireTime()
 * @method void setAppid(string $appid)
 * @method void setUserid(string $userid)
 * @method void setRequestToken(string $requestToken)
 * @method void setExpireTime(string $expireTime)
 */
class ExAppRequest extends Entity implements JsonSerializable {
	protected $appid;
	protected $userid;
	protected $requestToken;
	protected $expireTime;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['userid'])) {
			$this->setUserid($params['userid']);
		}
		if (isset($params['request_token'])) {
			$this->setRequestToken($params['request_token']);
		}
		if (isset($params['expire_time'])) {
			$this->setExpireTime($params['expire_time']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'app_id' => $this->getAppid(),
			'user_id' => $this->getUserid(),
			'request_token' => $this->getRequestToken(),
			'expire_time' => $this->getExpireTime(),
		];
	}
}
