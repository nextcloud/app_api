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
 * Class ExAppSetting
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getUserId()
 * @method string getAppId()
 * @method string getConfigKey()
 * @method string getValue()
 * @method void setUserId(string $userId)
 * @method void setAppId(string $appId)
 * @method void setConfigKey(string $key)
 * @method void setValue(string $value)
 */
class ExAppSetting extends Entity implements JsonSerializable {
	protected $appid;
	protected $name;
	protected $value;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		if (isset($params['userId'])) {
			$this->setUserId($params['userId']);
		}
		if (isset($params['appId'])) {
			$this->setAppId($params['appId']);
		}
		if (isset($params['configkey'])) {
			$this->setConfigKey($params['configkey']);
		}
		if (isset($params['value'])) {
			$this->setValue($params['value']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'user_id' => $this->getUserId(),
			'app_id' => $this->getAppId(),
			'configkey' => $this->getConfigKey(),
			'value' => $this->getValue(),
		];
	}
}
