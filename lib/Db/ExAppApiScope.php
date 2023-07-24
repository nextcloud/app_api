<?php

declare(strict_types=1);

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
