<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
 * @method array getFromLanguages()
 * @method array getToLanguages()
 * @method string getActionHandler()
 * @method string getActionDetectLang()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setFromLanguages(array $fromLanguages)
 * @method void setToLanguages(array $toLanguages)
 * @method void setActionHandler(string $actionHandler)
 * @method void setActionDetectLang(string $actionDetectLang)
 */
class TranslationProvider extends Entity implements \JsonSerializable {
	protected $appid;
	protected $name;
	protected $displayName;
	protected $fromLanguages;
	protected $toLanguages;
	protected $actionHandler;
	protected $actionDetectLang;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('fromLanguages', 'json');
		$this->addType('toLanguages', 'json');
		$this->addType('actionHandler', 'string');
		$this->addType('actionDetectLang', 'string');

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
		if (isset($params['action_detect_lang'])) {
			$this->setActionDetectLang($params['action_detect_lang']);
		} else {
			$this->setActionDetectLang('');
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
			'action_detect_lang' => $this->getActionDetectLang(),
		];
	}
}
