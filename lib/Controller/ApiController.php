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

namespace OCA\AppEcosystemV2\Controller;

use Psr\Log\LoggerInterface;

use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCP\AppFramework\Http\DataResponse;

class ApiController extends Controller {
	/** @var ?string */
	private $userId;

	/** @var AppEcosystemV2Service */
	private $service;

	/** @var IL10N */
	private $l;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		?string $userId,
		IRequest $request,
		AppEcosystemV2Service $appEcosystemV2Service,
		IL10N $l10n,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->userId = $userId;
		$this->service = $appEcosystemV2Service;
		$this->l = $l10n;
		$this->logger = $logger;
	}

	/**
	 * @NoCSRFRequired
	 */
	public function registerExternalApp(
		string $appName,
		string $appVersion,
		string $ncVersion,
		string $ocsVersion = 'v2',
	) {
		// TODO: check if app is already registered
		return null;
	}

	/**
	 * @NoCSRFRequired
	 */
	public function unregisterExternalApp(int $id) {
		$deletedExApp = $this->service->unregisterExApp($id);
		if ($deletedExApp === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l->t('ExApp not found'),
			], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([
			'success' => $deletedExApp->getId() === $id,
			'deletedExApp' => $deletedExApp,
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 *
	 * @return JSONResponse
	 */
	public function getAppStatus(string $appId): JSONResponse {
		return new JSONResponse([
			'success' => true,
			'appStatus' => [
				'appId'=> $appId,
			],
		], Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function sendNotification() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerSearchProvider() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerFileActionMenu() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerBackgroundJob() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function setAppConfigValue() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getAppConfigValue() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function setUserConfigValue() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getUserConfigValue() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerSettingsPage() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerSettingsSection() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerEventListener() {
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function registerDashboardWidget() {
	}
}
