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
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

class HarpController extends Controller {
	protected $request;

	public function __construct(
		IRequest                         $request,
		private readonly IAppConfig      $appConfig,
		private readonly ExAppService    $exAppService,
		private readonly LoggerInterface $logger,
		private readonly IThrottler			$throttler,
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

	#[PublicPage]
	#[NoCSRFRequired]
	public function getExAppMetadata(string $appId): DataResponse {
		// todo: temporary solution to use app config
		$harpKey = $this->appConfig->getValueString(Application::APP_ID, 'harp_shared_key');
		$headerHarpKey = $this->request->getHeader('HARP-SHARED-KEY');
		if ($headerHarpKey === '' || $headerHarpKey !== $harpKey) {
			$this->logger->error('Harp shared key is not valid');
			$this->throttler->registerAttempt(Application::APP_ID, $this->request->getRemoteAddress(), [
				'appid' => $appId,
			]);
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
}
