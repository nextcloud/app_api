<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Middleware;

use Exception;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Exceptions\AppAPIAuthNotValidException;
use OCA\AppAPI\Service\AppAPIService;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class AppAPIAuthMiddleware extends Middleware {

	public function __construct(
		private AppAPIService   $service,
		protected IRequest      $request,
		private IL10N           $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws AppAPIAuthNotValidException when a security check fails
	 * @throws \ReflectionException
	 */
	public function beforeController($controller, $methodName) {
		$reflectionMethod = new ReflectionMethod($controller, $methodName);

		$isAppAPIAuth = !empty($reflectionMethod->getAttributes(AppAPIAuth::class));
		if ($isAppAPIAuth) {
			if (!$this->request->getHeader('AUTHORIZATION-APP-API')) {
				throw new AppAPIAuthNotValidException($this->l->t('AppAPI authentication failed'), Http::STATUS_UNAUTHORIZED);
			}
			if (!$this->service->validateExAppRequestToNC($this->request)) {
				throw new AppAPIAuthNotValidException($this->l->t('AppAPI authentication failed'), Http::STATUS_UNAUTHORIZED);
			}
		}
	}

	/**
	 * If an AppAPIAuthNotValidException is being caught
	 *
	 * @param Controller $controller the controller that is being called
	 * @param string $methodName the name of the method that will be called on
	 *                           the controller
	 * @param Exception $exception the thrown exception
	 * @return Response a Response object or null in case that the exception could not be handled
	 * @throws Exception the passed in exception if it can't handle it
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if ($exception instanceof AppAPIAuthNotValidException) {
			$this->logger->debug($exception->getMessage(), [
				'exception' => $exception,
			]);
			return new JSONResponse(['message' => $exception->getMessage()], $exception->getCode());
		}

		throw $exception;
	}
}
