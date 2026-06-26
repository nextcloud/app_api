<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Middleware;

use Exception;
use OCA\AppAPI\Attribute\MaintenanceModeAvailable;
use OCA\AppAPI\Exceptions\MaintenanceModeException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use ReflectionMethod;

class MaintenanceModeMiddleware extends Middleware {

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @throws MaintenanceModeException when the server is in maintenance mode and the route is not allowed during it
	 * @throws \ReflectionException
	 */
	public function beforeController($controller, $methodName) {
		if (!$this->config->getSystemValueBool('maintenance', false)) {
			return;
		}
		$reflectionMethod = new ReflectionMethod($controller, $methodName);
		if (!empty($reflectionMethod->getAttributes(MaintenanceModeAvailable::class))) {
			return;
		}
		throw new MaintenanceModeException();
	}

	public function afterException($controller, $methodName, Exception $exception): Response {
		if ($exception instanceof MaintenanceModeException) {
			$response = new JSONResponse(['message' => $exception->getMessage()], $exception->getCode());
			$response->addHeader('X-Nextcloud-Maintenance-Mode', '1');
			$response->addHeader('Retry-After', '120');
			return $response;
		}

		throw $exception;
	}
}
