<?php

declare(strict_types=1);

namespace OCA\AppAPI\Middleware;

use Exception;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Exceptions\AppAPIAuthNotValidException;
use OCA\AppAPI\Service\AppAPIService;

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
		protected IRequest        $request,
		private IL10N           $l,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName) {
		$reflectionMethod = new ReflectionMethod($controller, $methodName);

		$isAppAPIAuth = !empty($reflectionMethod->getAttributes(AppAPIAuth::class));

		if ($isAppAPIAuth) {
			if (!$this->service->validateExAppRequestToNC($this->request)) {
				throw new AppAPIAuthNotValidException($this->l->t('AppAPIAuth authentication failed'), Http::STATUS_UNAUTHORIZED);
			}
		}
	}

	/**
	 * If an AEAuthNotValidException is being caught
	 *
	 * @return Response a Response object or null in case that the exception could not be handled
	 * @throws Exception the passed in exception if it can't handle it
	 */
	public function afterException($controller, $methodName, Exception $exception): Response {
		if ($exception instanceof AppAPIAuth) {
			$response = new JSONResponse([
				'message' => $exception->getMessage(),
			]);
			if (stripos($this->request->getHeader('Accept'), 'html') === false) {
				$response = new JSONResponse(
					['message' => $exception->getMessage()],
					$exception->getCode()
				);
			}

			$this->logger->debug($exception->getMessage(), [
				'exception' => $exception,
			]);
			return $response;
		}

		throw $exception;
	}
}
