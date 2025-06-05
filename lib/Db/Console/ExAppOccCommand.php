<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\Console;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ExAppOccCommand
 *
 * @package OCA\AppAPI\Db\Console
 *
 * @method string getAppid()
 * @method string getName()
 * @method string getDescription()
 * @method int getHidden()
 * @method array getArguments()
 * @method array getOptions()
 * @method array getUsages()
 * @method string getExecuteHandler()
 * @method void setAppid(string $appid)
 * @method void setName(string $name)
 * @method void setDescription(string $description)
 * @method void setHidden(int $hidden)
 * @method void setArguments(array $arguments)
 * @method void setOptions(array $options)
 * @method void setUsages(array $usages)
 * @method void setExecuteHandler(string $executeHandler)
 */
class ExAppOccCommand extends Entity implements JsonSerializable {
	protected $appid;
	protected $name;
	protected $description;
	protected $hidden;
	protected $arguments;
	protected $options;
	protected $usages;
	protected $executeHandler;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('hidden', 'integer');
		$this->addType('arguments', 'json');
		$this->addType('options', 'json');
		$this->addType('usages', 'json');
		$this->addType('executeHandler', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
		if (isset($params['description'])) {
			$this->setDescription($params['description']);
		} else {
			$this->setDescription('');
		}
		if (isset($params['hidden'])) {
			$this->setHidden($params['hidden']);
		}
		if (isset($params['arguments'])) {
			$this->setArguments($params['arguments']);
		}
		if (isset($params['options'])) {
			$this->setOptions($params['options']);
		}
		if (isset($params['usages'])) {
			$this->setUsages($params['usages']);
		}
		if (isset($params['execute_handler'])) {
			$this->setExecuteHandler($params['execute_handler']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'hidden' => $this->getHidden(),
			'arguments' => $this->getArguments(),
			'options' => $this->getOptions(),
			'usages' => $this->getUsages(),
			'execute_handler' => $this->getExecuteHandler(),
		];
	}
}
