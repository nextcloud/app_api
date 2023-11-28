<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class InitialState
 *
 * @package OCA\AppAPI\Db\UI
 *
 * @method string getAppId()
 * @method string getType()
 * @method string getKey()
 * @method string getValue()
 * @method void setAppId(string $appid)
 * @method void setType(string $type)
 * @method void setKey(string $key)
 * @method void setValue(array $value)
 */
class InitialState extends Entity implements JsonSerializable {
	protected $appid;
	protected $type;
	protected $key;
	protected $value;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('type', 'string');
		$this->addType('key', 'string');
		$this->addType('value', 'json');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppId($params['appid']);
		}
		if (isset($params['type'])) {
			$this->setType($params['type']);
		}
		if (isset($params['key'])) {
			$this->setKey($params['key']);
		}
		if (isset($params['value'])) {
			$this->setValue($params['value']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppId(),
			'type' => $this->getType(),
			'key' => $this->getKey(),
			'value' => $this->getValue(),
		];
	}
}
