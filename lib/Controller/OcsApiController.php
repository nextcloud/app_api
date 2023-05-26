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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCP\IRequest;

class OCSApiController extends OCSController {
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IRequest $request,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * 
	 * @param string $appId
	 * @param int $level
	 * @param string $message
	 *
	 * @return DataResponse
	 */
	public function log(
		string $appId,
		int $level,
		string $message,
	): DataResponse {
		// TODO: Add separate logging and log level for each ExApp
		// TODO: Add general intermediate versions and auth data checks
		try {
			$this->logger->log($level, $message, [
				'app' => $appId,
			]);
			return new DataResponse(1, Http::STATUS_OK);
		} catch (\Psr\Log\InvalidArgumentException) {
			return new DataResponse(0, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
