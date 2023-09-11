<?php

declare(strict_types=1);

namespace OCA\AppAPI;

use OCA\AppAPI\AppInfo\Application;

use OCA\DAV\Connector\Sabre\Auth;
use OCP\IRequest;
use OCP\ISession;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class AppAPIAuthBackend implements BackendInterface {
	private IRequest $request;
	private ISession $session;

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
			if ($sessionUserId === $userIdHeader && $davAuthenticated === $sessionUserId) {
				$authString = 'principals/' . Application::APP_ID . '/' . $this->session->get('user_id');
				return [true, $authString];
			}
		}
		return [false, 'AppAPIAuth has not passed'];
	}

	public function challenge(RequestInterface $request, ResponseInterface $response) {
	}
}
