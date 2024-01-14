<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\Translation;

use OCP\AppFramework\Db\Entity;

/**
 * Class TranslationProvider
 *
 * @package OCA\AppAPI\Db\Translation
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getFromLanguages()
 * @method string getToLanguages()
 * @method string getActionHandler()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setFromLanguages(string $fromLanguages)
 * @method void setToLanguages(string $toLanguages)
 * @method void setActionHandler(string $actionHandler)
 */
class TranslationProvider extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $fromLanguages;
	protected $toLanguages;
	protected $actionHandler;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('fromLanguages', 'string');
		$this->addType('toLanguages', 'string');
		$this->addType('actionHandler', 'string');

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
		if (isset($params['from_languages'])) {
			$this->setFromLanguages($params['from_languages']);
		}
		if (isset($params['to_languages'])) {
			$this->setToLanguages($params['to_languages']);
		}
		if (isset($params['action_handler'])) {
			$this->setActionHandler($params['action_handler']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'from_languages' => $this->getFromLanguages(),
			'to_languages' => $this->getToLanguages(),
			'action_handler' => $this->getActionHandler(),
		];
	}
}
