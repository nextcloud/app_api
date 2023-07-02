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
 * Class ExAppApiScope
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getApiRoute()
 * @method int getScopeGroup()
 * @method string getName()
 * @method void setApiRoute(string $apiRoute)
 * @method void setScopeGroup(int $scopeGroup)
 * @method void setName(string $name)
 */
class ExAppApiScope extends Entity implements JsonSerializable {
	protected $apiRoute;
	protected $scopeGroup;
	protected $name;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('apiRoute', 'string');
		$this->addType('scopeGroup', 'int');
		$this->addType('name', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['api_route'])) {
			$this->setApiRoute($params['api_route']);
		}
		if (isset($params['scope_group'])) {
			$this->setScopeGroup($params['scope_group']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'api_route' => $this->apiRoute,
			'scope_group' => $this->scopeGroup,
			'name' => $this->name,
		];
	}
}
