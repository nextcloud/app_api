<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppUser
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAppid()
 * @method string getUserid()
 * @method void setAppid(string $appid)
 * @method void setUserid(string $userid)
 */
class ExAppUser extends Entity implements JsonSerializable {
	protected $appid;
	protected $userid;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('userid', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['userid'])) {
			$this->setUserid($params['userid']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'userid' => $this->getUserid(),
		];
	}
}
