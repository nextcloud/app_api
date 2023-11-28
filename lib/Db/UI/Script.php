<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\UI;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Script
 *
 * @package OCA\AppAPI\Db\UI
 *
 * @method string getAppId()
 * @method string getType()
 * @method string getPath()
 * @method string getAfterAppId()
 * @method void setAppId(string $appid)
 * @method void setType(string $type)
 * @method void setPath(string $path)
 * @method void setAfterAppId(string $afterAppId)
 */
class Script extends Entity implements JsonSerializable {
	protected $appid;
	protected $type;
	protected $path;
	protected $afterAppId;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('type', 'string');
		$this->addType('path', 'string');
		$this->addType('afterAppId', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppId($params['appid']);
		}
		if (isset($params['type'])) {
			$this->setType($params['type']);
		}
		if (isset($params['path'])) {
			$this->setPath($params['path']);
		}
		if (isset($params['afterAppId'])) {
			$this->setAfterAppId($params['afterAppId']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppId(),
			'type' => $this->getType(),
			'path' => $this->getPath(),
			'afterAppId' => $this->getAfterAppId(),
		];
	}
}
