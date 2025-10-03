<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class HarpController extends Controller {
	protected $request;

	public function __construct(
		IRequest                             $request,
		private readonly ExAppService        $exAppService,
		private readonly LoggerInterface     $logger,
		private readonly IThrottler          $throttler,
		private readonly IUserManager        $userManager,
		private readonly IGroupManager       $groupManager,
		private readonly ICrypto             $crypto,
		private readonly ?string             $userId,
		private readonly HarpService		 $harpService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	private function validateHarpSharedKey(ExApp $exApp): bool {
		try {
			if (!isset($exApp->getDeployConfig()['haproxy_password'])) {
				$this->logger->error('Harp shared key is not set. Invalid daemon config.');
				return false;
			}
			$harpKey = $this->crypto->decrypt($exApp->getDeployConfig()['haproxy_password']);
		} catch (\Exception $e) {
			$this->logger->error('Failed to decrypt harp shared key. Invalid daemon config.', ['exception' => $e]);
			return false;
		}

		$headerHarpKey = $this->request->getHeader('HARP-SHARED-KEY');
		if ($headerHarpKey === '' || $headerHarpKey !== $harpKey) {
			$this->logger->error('Harp shared key is not valid');
			$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress(), [
				'appid' => $exApp->getAppid(),
			]);
			return false;
		}
		return true;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppMetadata(string $appId): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$this->logger->error('ExApp not found', ['appId' => $appId]);
			// return the same response as invalid harp key to prevent ex-app guessing
			return new DataResponse(['message' => 'Harp shared key is not valid'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->validateHarpSharedKey($exApp)) {
			// Protection for guessing HaRP shared key
			$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress(), [
				'appid' => $appId,
			]);
			$this->logger->error('Harp shared key is not valid', ['appId' => $appId]);
			return new DataResponse(['message' => 'Harp shared key is not valid'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->harpService->getHarpExApp($exApp));
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

	/**
	 * access_level:
	 * 0: PUBLIC
	 * 1: USER
	 * 2: ADMIN
	 * @return DataResponse array{ user_id: string, access_level: int }
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function getUserInfo(string $appId): DataResponse {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			$this->logger->error('ExApp not found', ['appId' => $appId]);
			// Protection for guessing installed ExApps list
			$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress(), [
				'appid' => $appId,
			]);
			// return the same response as invalid harp key to prevent ex-app guessing
			return new DataResponse(['message' => 'Harp shared key is not valid'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->validateHarpSharedKey($exApp)) {
			return new DataResponse(['message' => 'Harp shared key is not valid'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->userId === null) {
			$this->logger->debug('No user found in the harp request');
			return new DataResponse([
				'user_id' => '',
				'access_level' => ExAppRouteAccessLevel::PUBLIC->value,
			]);
		}

		if (!$this->isUserEnabled($this->userId)) {
			$this->logger->debug('User is not enabled in the harp request', ['userId' => $this->userId]);
			return new DataResponse([
				'user_id' => $this->userId,
				'access_level' => ExAppRouteAccessLevel::PUBLIC->value,
			]);
		}

		if ($this->groupManager->isAdmin($this->userId)) {
			return new DataResponse([
				'user_id' => $this->userId,
				'access_level' => ExAppRouteAccessLevel::ADMIN->value,
			]);
		}

		return new DataResponse([
			'user_id' => $this->userId,
			'access_level' => ExAppRouteAccessLevel::USER->value,
		]);
	}
}

enum ExAppRouteAccessLevel: int {
	case PUBLIC = 0;
	case USER = 1;
	case ADMIN = 2;
}
