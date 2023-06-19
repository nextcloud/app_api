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
 * Class ExAppPreference
 *
 * @package OCA\AppEcosystemV2\Db
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
			'user_id' => $this->getUserid(),
			'app_id' => $this->getAppid(),
			'configkey' => $this->getConfigkey(),
			'configvalue' => $this->getConfigvalue(),
		];
	}
}
