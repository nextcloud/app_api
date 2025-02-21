<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
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

		return new DataResponse(HarpService::getHarpExApp($exApp));
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
	public function getUserInfo(): DataResponse {
		if (!$this->validateHarpSharedKey()) {
			return new DataResponse(['message' => 'Invalid token'], Http::STATUS_UNAUTHORIZED);
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
