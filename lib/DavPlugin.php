<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2;

use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

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
	private IRequest $request;
	private ISession $session;
	private AppEcosystemV2Service $service;

	public function __construct(IRequest $request, ISession $session, AppEcosystemV2Service $service) {
		$this->request = $request;
		$this->session = $session;
		$this->service = $service;
	}

	public function initialize(Server $server) {
		// before auth
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 8);
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response) {
		if ($this->request->getHeader('AE-SIGNATURE')) {
			if ($this->service->validateExAppRequestToNC($this->request, true)) {
				$this->session->set(Auth::DAV_AUTHENTICATED, $this->request->getHeader('NC-USER-ID'));
			}
		}
	}
}
