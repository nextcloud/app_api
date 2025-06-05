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
 * Class ExFilesActionsMenu
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getMime()
 * @method string getPermissions()
 * @method int getOrder()
 * @method string getIcon()
 * @method string getActionHandler()
 * @method string getVersion()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setMime(string $mime)
 * @method void setPermissions(string $permissions)
 * @method void setOrder(int $order)
 * @method void setIcon(string $icon)
 * @method void setActionHandler(string $actionHandler)
 * @method void setVersion(string $version)
 */
class FilesActionsMenu extends Entity implements JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $mime;
	protected $permissions;
	protected $order;
	protected $icon;
	protected $actionHandler;
	protected $version;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('mime', 'string');
		$this->addType('permissions', 'string');
		$this->addType('order', 'integer');
		$this->addType('icon', 'string');
		$this->addType('actionHandler', 'string');
		$this->addType('version', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
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
		if (isset($params['action_handler'])) {
			$this->setActionHandler($params['action_handler']);
		}
		if (isset($params['version'])) {
			$this->setVersion($params['version']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'mime' => $this->getMime(),
			'permissions' => $this->getPermissions(),
			'order' => $this->getOrder(),
			'icon' => $this->getIcon(),
			'action_handler' => $this->getActionHandler(),
			'version' => $this->getVersion(),
		];
	}
}
