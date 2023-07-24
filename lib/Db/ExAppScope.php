<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppScope
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getAppid()
 * @method string getScopeGroup()
 * @method void setAppid(string $appid)
 * @method void setScopeGroup(string $scopeGroup)
 */
class ExAppScope extends Entity implements JsonSerializable {
	protected $appid;
	protected $scopeGroup;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('scopeGroup', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['scope_group'])) {
			$this->setScopeGroup($params['scope_group']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'scope_group' => $this->getScopeGroup(),
		];
	}
}
