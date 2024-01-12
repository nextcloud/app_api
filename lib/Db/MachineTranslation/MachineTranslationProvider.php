<?php

declare(strict_types=1);

namespace OCA\AppAPI\Db\MachineTranslation;

use OCP\AppFramework\Db\Entity;

/**
 * Class MachineTranslationProvider
 *
 * @package OCA\AppAPI\Db\MachineTranslation
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getFromLanguages()
 * @method string getFromLanguagesLabels()
 * @method string getToLanguages()
 * @method string getToLanguagesLabels()
 * @method string getActionHandler()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setFromLanguages(string $fromLanguages)
 * @method void setFromLanguagesLabels(string $fromLanguagesLabels)
 * @method void setToLanguages(string $toLanguages)
 * @method void setToLanguagesLabels(string $toLanguagesLabels)
 * @method void setActionHandler(string $actionHandler)
 */
class MachineTranslationProvider extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $fromLanguages;
	protected $fromLanguagesLabels;
	protected $toLanguages;
	protected $toLanguagesLabels;
	protected $actionHandler;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('fromLanguages', 'string');
		$this->addType('fromLanguagesLabels', 'string');
		$this->addType('toLanguages', 'string');
		$this->addType('toLanguagesLabels', 'string');
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
		if (isset($params['from_languages_labels'])) {
			$this->setFromLanguagesLables($params['from_languages_labels']);
		}
		if (isset($params['to_languages'])) {
			$this->setToLanguages($params['to_languages']);
		}
		if (isset($params['to_languages_labels'])) {
			$this->setToLanguagesLables($params['to_languages_labels']);
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
			'from_languages_labels' => $this->getFromLanguagesLables(),
			'to_languages' => $this->getToLanguages(),
			'to_languages_labels' => $this->getToLanguagesLables(),
			'action_handler' => $this->getActionHandler(),
		];
	}
}
