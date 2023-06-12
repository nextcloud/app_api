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
use OCA\DAV\Connector\Sabre\Auth;
use OCP\ISession;
use OCP\IRequest;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class AEAuthBackend implements BackendInterface {
	/** @var IRequest */
	private $request;

	/** @var ISession */
	private $session;

	public function __construct(
		IRequest $request,
		ISession $session,
	) {
		$this->request = $request;
		$this->session = $session;
	}

	public function check(RequestInterface $request, ResponseInterface $response) {
		if ($this->request->getHeader('AE-SIGNATURE')) {
			$davAuthenticated = $this->session->get(Auth::DAV_AUTHENTICATED);
			$userIdHeader = $this->request->getHeader('NC-USER-ID');
			$sessionUserId = $this->session->get('user_id');
			// TODO: Add scopes check
			if ($sessionUserId === $userIdHeader && $davAuthenticated === $sessionUserId) {
				$authString = 'principals/' . Application::APP_ID . '/' . $this->session->get('user_id');
				return [true, $authString];
			}
		}
		return [false, 'AEAuth has not passed'];
	}

	public function challenge(RequestInterface $request, ResponseInterface $response) {
	}
}
