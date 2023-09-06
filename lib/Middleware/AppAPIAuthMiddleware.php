<?php

declare(strict_types=1);

namespace OCA\AppAPI\Middleware;

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
	private AppAPIService $service;
	protected IRequest $request;
	private IL10N $l;
	private LoggerInterface $logger;

	public function __construct(
		AppAPIService   $service,
		IRequest        $request,
		IL10N           $l,
		LoggerInterface $logger,
	) {
		$this->service = $service;
		$this->request = $request;
		$this->l = $l;
		$this->logger = $logger;
	}

	public function beforeController($controller, $methodName) {
		$reflectionMethod = new ReflectionMethod($controller, $methodName);

		$isAppEcosystemAuth = !empty($reflectionMethod->getAttributes(AppAPIAuth::class));

		if ($isAppEcosystemAuth) {
			if (!$this->service->validateExAppRequestToNC($this->request)) {
				throw new AppAPIAuthNotValidException($this->l->t('AppEcosystemV2 authentication failed'), Http::STATUS_UNAUTHORIZED);
			}
		}
	}

	/**
	 * If an AEAuthNotValidException is being caught
	 *
	 * @param Controller $controller the controller that is being called
	 * @param string $methodName the name of the method that will be called on
	 *                           the controller
	 * @param \Exception $exception the thrown exception
	 * @return Response a Response object or null in case that the exception could not be handled
	 * @throws \Exception the passed in exception if it can't handle it
	 */
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if ($exception instanceof AEAuthNotValidException) {
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
