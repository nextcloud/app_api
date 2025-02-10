<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

class HarpController extends Controller {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly IAppConfig      $appConfig,
		private readonly ExAppService    $exAppService,
		private readonly LoggerInterface $logger,
		private readonly IThrottler      $throttler,
		private readonly IUserManager    $userManager,
		private readonly IGroupManager   $groupManager,
		private readonly ?string         $userId,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	public function mapExAppRouteAccessLevelNumberToName(int $accessLevel): string {
		return match($accessLevel) {
			0 => 'PUBLIC',
			1 => 'USER',
			2 => 'ADMIN',
			// most restrictive access level
			default => 'ADMIN',
		};
	}

	private function validateHarpSharedKey(array $metadata = []): bool {
		$harpKey = $this->appConfig->getValueString(Application::APP_ID, 'harp_shared_key');
		$headerHarpKey = $this->request->getHeader('HARP-SHARED-KEY');
		if ($headerHarpKey === '' || $headerHarpKey !== $harpKey) {
			$this->logger->error('Harp shared key is not valid');
			$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress(), $metadata);
			return false;
		}
		return true;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppMetadata(string $appId): DataResponse {
		if (!$this->validateHarpSharedKey(['appid' => $appId])) {
			return new DataResponse(['message' => 'Harp shared key is not valid'], Http::STATUS_UNAUTHORIZED);
		}

		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$this->logger->error(sprintf('ExApp with appId %s not found.', $appId));
			// Protection for guessing installed ExApps list
			$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress(), [
				'appid' => $appId,
			]);
			return new DataResponse(['message' => 'ExApp not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([
			'exapp_token' => $exApp->getSecret(),
			'exapp_version' => $exApp->getVersion(),
			'port' => $exApp->getPort(),
			'routes' => array_map(function ($route) {
				$accessLevel = $this->mapExAppRouteAccessLevelNumberToName($route['access_level']);
				$bruteforceList = json_decode($route['bruteforce_protection'], true);
				if (!$bruteforceList) {
					$bruteforceList = [];
				}
				return [
					'url' => $route['url'],
					'access_level' => $accessLevel,
					'bruteforce_protection' => $bruteforceList,
				];
			}, $exApp->getRoutes()),
		]);
	}

	protected function isUserEnabled(string $userId): bool {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->debug('User not found', ['userId' => $userId]);
			return false;
		}

		if (!$user->isEnabled()) {
			$this->logger->debug('User is not enabled', ['userId' => $userId]);
			return false;
		}

		return true;
	}

	// todo: remove this
	// protected function validateToken(string $tokenId): bool {
	// 	try {
	// 		$dbToken = $this->tokenProvider->getToken($tokenId);
	// 	} catch (InvalidTokenException $ex) {
	// 		$this->logger->debug('Invalid token', ['exception' => $ex]);
	// 		return false;
	// 	}

	// 	try {
	// 		$pwd = $this->tokenProvider->getPassword($dbToken, $tokenId);
	// 	} catch (InvalidTokenException $ex) {
	// 		$this->logger->debug('Invalid token', ['exception' => $ex]);
	// 		return false;
	// 	} catch (PasswordlessTokenException $ex) {
	// 		$this->logger->debug('Password-less token, accepting', ['exception' => $ex]);
	// 		if ($this->isUserEnabled($dbToken->getUID())) {
	// 			return true;
	// 		}
	// 		return false;
	// 	}

	// 	if ($this->userManager->checkPassword($dbToken->getLoginName(), $pwd) === false) {
	// 		// don't do anything, just return false
	// 		return false;
	// 	}

	// 	return $this->isUserEnabled($dbToken->getUID());
	// }

	/**
	 * @return DataResponse { user_id: string|null, access_level: string }
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function getUserInfo(string $tokenId): DataResponse {
		if (!$this->validateHarpSharedKey(['tokenId' => $tokenId])) {
			return new DataResponse(['message' => 'Invalid token'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->userId === null) {
			$this->logger->debug('No user found in the harp request');
			return new DataResponse([
				'user_id' => null,
				'access_level' => 'PUBLIC',
			]);
		}

		if (!$this->isUserEnabled($this->userId)) {
			$this->logger->debug('User is not enabled in the harp request', ['userId' => $this->userId]);
			return new DataResponse([
				'user_id' => $this->userId,
				'access_level' => 'PUBLIC',
			]);
		}

		if ($this->groupManager->isAdmin($this->userId)) {
			return new DataResponse([
				'user_id' => $this->userId,
				'access_level' => 'ADMIN',
			]);
		}

		return new DataResponse([
			'user_id' => $this->userId,
			'access_level' => 'USER',
		]);
	}
}
