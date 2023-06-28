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
 * Class ExFilesActionsMenu
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getMime()
 * @method string getPermissions()
 * @method string getOrder()
 * @method string getIcon()
 * @method string getIconClass()
 * @method string getActionHandler()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setMime(string $mime)
 * @method void setPermissions(string $permissions)
 * @method void setOrder(string $order)
 * @method void setIcon(string $icon)
 * @method void setIconClass(string $iconClass)
 * @method void setActionHandler(string $actionHandler)
 */
class ExFilesActionsMenu extends Entity implements JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $mime;
	protected $permissions;
	protected $order;
	protected $icon;
	protected $iconClass;
	protected $actionHandler;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('mime', 'string');
		$this->addType('permissions', 'string');
		$this->addType('order', 'string');
		$this->addType('icon', 'string');
		$this->addType('iconClass', 'string');
		$this->addType('actionHandler', 'string');
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
		if (isset($params['display_name'])) {
			$this->setDisplayName($params['display_name']);
		}
		if (isset($params['mime'])) {
			$this->setMime($params['mime']);
		}
		if (isset($params['permissions'])) {
			$this->setPermissions($params['permissions']);
		}
		if (isset($params['order'])) {
			$this->setOrder($params['order']);
		}
		if (isset($params['icon'])) {
			$this->setIcon($params['icon']);
		}
		if (isset($params['icon_class'])) {
			$this->setIconClass($params['icon_class']);
		}
		if (isset($params['action_handler'])) {
			$this->setActionHandler($params['action_handler']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'mime' => $this->getMime(),
			'permissions' => $this->getPermissions(),
			'order' => $this->getOrder(),
			'icon' => $this->getIcon(),
			'icon_class' => $this->getIconClass(),
			'action_handler' => $this->getActionHandler(),
		];
	}
}
