<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppApiScope
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getApiRoute()
 * @method int getScopeGroup()
 * @method string getName()
 * @method int getUserCheck()
 * @method void setApiRoute(string $apiRoute)
 * @method void setScopeGroup(int $scopeGroup)
 * @method void setName(string $name)
 * @method void setUserCheck(int $userCheck)
 */
class ExAppApiScope extends Entity implements JsonSerializable {
	protected $apiRoute;
	protected $scopeGroup;
	protected $name;
	protected $userCheck;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('apiRoute', 'string');
		$this->addType('scopeGroup', 'int');
		$this->addType('name', 'string');
		$this->addType('userCheck', 'int');

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
		if (isset($params['user_check'])) {
			$this->setUserCheck($params['user_check']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'api_route' => $this->getApiRoute(),
			'scope_group' => $this->getScopeGroup(),
			'name' => $this->getName(),
			'user_check' => $this->getUserCheck(),
		];
	}
}
