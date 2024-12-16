<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI;

use OCA\AppAPI\Service\AppAPIService;

use OCA\DAV\Connector\Sabre\Auth;
use OCP\IRequest;
use OCP\ISession;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * @psalm-suppress UndefinedClass, MissingDependency
 */
class DavPlugin extends ServerPlugin {

	public function __construct(private IRequest $request,
		private ISession $session,
		private AppAPIService $service) {
	}

	public function initialize(Server $server) {
		// before auth
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 8);
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response) {
		if ($this->request->getHeader('AUTHORIZATION-APP-API')) {
			if ($this->service->validateExAppRequestToNC($this->request, true)) {
				$this->session->set(Auth::DAV_AUTHENTICATED, explode(':', base64_decode($this->request->getHeader('AUTHORIZATION-APP-API')), 2)[0]);
			}
		}
	}
}
