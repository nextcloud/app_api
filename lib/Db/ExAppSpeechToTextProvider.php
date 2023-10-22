<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppSpeechToTextProvider
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getDescription()
 * @method string getActionHandlerRoute()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setDescription(string $description)
 * @method void setActionHandlerRoute(string $actionHandlerRoute)
 */
class ExAppSpeechToTextProvider extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $actionHandlerRoute;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('actionHandlerRoute', 'string');

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
		if (isset($params['action_handler_route'])) {
			$this->setActionHandlerRoute($params['action_handler_route']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'action_handler_route' => $this->getActionHandlerRoute(),
		];
	}
}
