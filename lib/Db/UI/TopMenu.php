<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class MenuEntry
 *
 * @package OCA\AppAPI\Db\MenuEntry
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getIconUrl()
 * @method int getAdminRequired()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setIconUrl(string $iconUrl)
 * @method void setAdminRequired(int $adminRequired)
 */
class TopMenu extends Entity implements JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $iconUrl;
	protected $adminRequired;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('display_name', 'string');
		$this->addType('icon_url', 'string');
		$this->addType('admin_required', 'integer');

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
		if (isset($params['icon_url'])) {
			$this->setIconUrl($params['icon_url']);
		}
		if (isset($params['admin_required'])) {
			$this->setAdminRequired($params['admin_required']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'icon_url' => $this->getIconUrl(),
			'admin_required' => $this->getAdminRequired(),
		];
	}
}
