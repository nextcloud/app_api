<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppTextProcessingProvider
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getDescription()
 * @method string getActionHandlerRoute()
 * @method string getActionType()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setDescription(string $description)
 * @method void setActionHandlerRoute(string $actionHandlerRoute)
 * @method void setActionType(string $actionType)
 */
class ExAppTextProcessingProvider extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $description;
	protected $actionHandlerRoute;
	protected $actionType;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('description', 'string');
		$this->addType('actionHandlerRoute', 'string');
		$this->addType('actionType', 'string');

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
		if (isset($params['description'])) {
			$this->setDescription($params['description']);
		}
		if (isset($params['action_handler_route'])) {
			$this->setActionHandlerRoute($params['action_handler_route']);
		}
		if (isset($params['action_type'])) {
			$this->setActionType($params['action_type']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'description' => $this->getDescription(),
			'action_handler_route' => $this->getActionHandlerRoute(),
			'action_type' => $this->getActionType(),
		];
	}
}
