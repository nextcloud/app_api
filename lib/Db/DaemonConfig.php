<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class DaemonConfig
 *
 * @package OCA\AppAPI\Db
 *
 * @method string getAcceptsDeployId()
 * @method string getName()
 * @method string getDisplayName()
 * @method string getProtocol()
 * @method string getHost()
 * @method array getDeployConfig()
 * @method void setAcceptsDeployId(string $acceptsDeployId)
 * @method void setName(string $name)
 * @method void setDisplayName(string $displayName)
 * @method void setProtocol(string $protocol)
 * @method void setHost(string $host)
 * @method void setDeployConfig(array $deployConfig)
 */
class DaemonConfig extends Entity implements JsonSerializable {
	protected $name;
	protected $displayName;
	protected $acceptsDeployId;
	protected $protocol;
	protected $host;
	protected $deployConfig;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		$this->addType('acceptsDeployId', 'string');
		$this->addType('name', 'string');
		$this->addType('displayName', 'string');
		$this->addType('protocol', 'string');
		$this->addType('host', 'string');
		$this->addType('deployConfig', 'json');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['accepts_deploy_id'])) {
			$this->setAcceptsDeployId($params['accepts_deploy_id']);
		}
		if (isset($params['name'])) {
			$this->setName($params['name']);
		}
		if (isset($params['display_name'])) {
			$this->setDisplayName($params['display_name']);
		}
		if (isset($params['protocol'])) {
			$this->setProtocol($params['protocol']);
		}
		if (isset($params['host'])) {
			$this->setHost($params['host']);
		}
		if (isset($params['deploy_config'])) {
			$this->setDeployConfig($params['deploy_config']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'accepts_deploy_id' => $this->getAcceptsDeployId(),
			'name' => $this->getName(),
			'display_name' => $this->getDisplayName(),
			'protocol' => $this->getProtocol(),
			'host' => $this->getHost(),
			'deploy_config' => $this->getDeployConfig(),
		];
	}
}
