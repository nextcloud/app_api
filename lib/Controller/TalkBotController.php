<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\TalkBotsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

class TalkBotController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly ExAppService    $service,
		private readonly AppAPIService	 $appAPIService,
		private readonly TalkBotsService $talkBotsService,
		private readonly LoggerInterface $logger,
		private readonly IThrottler      $throttler,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	/**
	 * @param string $name bot display name
	 * @param string $route ExApp route to post messages
	 * @param string $description
	 *
	 * @throws OCSBadRequestException
	 * @return Response
	 */
	#[AppAPIAuth]
	#[NoCSRFRequired]
	#[PublicPage]
	public function registerExAppTalkBot(string $name, string $route, string $description): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$botRegistered = $this->talkBotsService->registerExAppBot(
			$exApp,
			$name,
			ltrim($route, '/'),
			$description,
		);
		if ($botRegistered === null) {
			throw new OCSBadRequestException('Talk bots could not be registered');
		}
		return new DataResponse($botRegistered);
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[AppAPIAuth]
	#[NoCSRFRequired]
	#[PublicPage]
	public function unregisterExAppTalkBot(string $route): Response {
		$appId = $this->request->getHeader('EX-APP-ID');
		$exApp = $this->service->getExApp($appId);
		$botUnregistered = $this->talkBotsService->unregisterExAppBot($exApp, ltrim($route, '/'));
		if ($botUnregistered === null) {
			throw new OCSNotFoundException('Talk bots could not be unregistered');
		}
		return new DataResponse($botUnregistered);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function proxyTalkMessage(string $appId, string $route): Response {
		$this->throttler->sleepDelayOrThrowOnMax($this->request->getRemoteAddress(), Application::APP_ID);
		$exApp = $this->service->getExApp($appId);

		if (($exApp !== null) && ($exApp->getEnabled())) {
			$bot_secret = $this->talkBotsService->getTalkBotSecret($appId, $route);
			if ($bot_secret !== null) {
				$digest = hash_hmac(
					'sha256',
					$this->request->getHeader('X-Nextcloud-Talk-Random') . file_get_contents('php://input'),
					$bot_secret);
				if (!hash_equals($digest, strtolower($this->request->getHeader('X-Nextcloud-Talk-Signature')))) {
					$this->throttler->registerAttempt(
						Application::APP_ID,
						$this->request->getRemoteAddress(),
						['proxyTalkMessage' => $appId, 'route' => $route, 'reason' => 'invalid hash']
					);
					$this->logger->error(sprintf("Invalid ExApp TalkBot hash provided: %s:%s.", $appId, $route));
					return new DataResponse([], Http::STATUS_NOT_FOUND);
				}
				$this->throttler->resetDelay(
					$this->request->getRemoteAddress(),
					Application::APP_ID,
					['proxyTalkMessage' => $appId, 'route' => $route]
				);
				$response = $this->appAPIService->requestToExApp(
					$exApp, '/' . $route, null,
					params: $this->request->getParams(),
					request: $this->request
				);
				$return_response = new DataResponse();
				if (is_array($response)) {
					$return_response->setStatus(500);
				}
				return $return_response;
			}
		}
		$this->throttler->registerAttempt(
			Application::APP_ID,
			$this->request->getRemoteAddress(),
			['proxyTalkMessage' => $appId, 'route' => $route]
		);
		$this->logger->error(sprintf("Invalid request to ExApp TalkBot: %s:%s.", $appId, $route));
		return new DataResponse([], Http::STATUS_NOT_FOUND);
	}
}
