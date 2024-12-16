<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI;

use OCA\DAV\Connector\Sabre\Auth;
use OCP\IRequest;
use OCP\ISession;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class AppAPIAuthBackend implements BackendInterface {

	public function __construct(
		private IRequest $request,
		private ISession $session,
	) {
	}

	public function check(RequestInterface $request, ResponseInterface $response): array {
		if ($this->request->getHeader('AUTHORIZATION-APP-API')) {
			$davAuthenticated = $this->session->get(Auth::DAV_AUTHENTICATED);
			$userIdHeader = explode(':', base64_decode($this->request->getHeader('AUTHORIZATION-APP-API')), 2)[0];
			$sessionUserId = $this->session->get('user_id');
			if ($sessionUserId === $userIdHeader && $davAuthenticated === $sessionUserId) {
				$authString = 'principals/users/' . $this->session->get('user_id');
				return [true, $authString];
			}
		}
		return [false, 'AppAPIAuth has not passed'];
	}

	public function challenge(RequestInterface $request, ResponseInterface $response) {
	}
}
