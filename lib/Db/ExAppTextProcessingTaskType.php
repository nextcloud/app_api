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
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setDescription(string $description)
 */
class ExAppTextProcessingTaskType extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $description;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('description', 'string');

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
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'description' => $this->getDescription(),
		];
	}
}
