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

namespace OCA\AppEcosystemV2;

use OCA\AppEcosystemV2\AppInfo\Application;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use OCP\IRequest;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCA\DAV\Connector\Sabre\Auth;
use OCP\ISession;

class DavPlugin extends ServerPlugin {
	/** @var Server */
	private $server;

	/** @var IRequest */
	private $request;

	/** @var ISession */
	private $session;

	/** @var AppEcosystemV2Service */
	private $service;

	public function __construct(IRequest $request, ISession $session, AppEcosystemV2Service $service) {
		$this->request = $request;
		$this->session = $session;
		$this->service = $service;
	}

	public function initialize(Server $server) {
		// before auth
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 9);
		$this->server = $server;
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response) {
		if ($this->service->validateExAppRequestToNC($this->request, true)) {
			$this->session->set(Auth::DAV_AUTHENTICATED, Application::APP_ID);
		}
	}
}
