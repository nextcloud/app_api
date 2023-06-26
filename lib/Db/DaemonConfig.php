<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class DaemonConfig
 *
 * @package OCA\AppEcosystemV2\Db
 *
 * @method string getAcceptsDeployId()
 * @method string getDisplayName()
 * @method string getProtocol()
 * @method string getHost()
 * @method string getPort()
 * @method string getDeployConfig()
 * @method void setAcceptsDeployId(string $acceptsDeployId)
 * @method void setDisplayName(string $displayName)
 * @method void setProtocol(string $protocol)
 * @method void setHost(string $host)
 * @method void setPort(string $port)
 * @method void setDeployConfig(string $deployConfig)
 */
class DaemonConfig extends Entity implements JsonSerializable {
	protected $acceptsDeployId;
	protected $displayName;
	protected $protocol;
	protected $host;
	protected $port;
	protected $deployConfig;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		if (isset($params['accepts_deploy_id'])) {
			$this->setAcceptsDeployId($params['accepts_deploy_id']);
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
		if (isset($params['port'])) {
			$this->setPort($params['port']);
		}
		if (isset($params['deploy_config'])) {
			$this->setDeployConfig($params['deploy_config']);
		}
	}

	public function jsonSerialize(): array {
		return [
			'accepts_deploy_id' => $this->getAcceptsDeployId(),
			'display_name' => $this->getDisplayName(),
			'protocol' => $this->getProtocol(),
			'host' => $this->getHost(),
			'port' => $this->getPort(),
			'deploy_config' => $this->getDeployConfig(),
		];
	}
}
