<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Middleware;

use OCA\AppEcosystemV2\Attribute\AppEcosystemAuth;
use OCA\AppEcosystemV2\Exceptions\AEAuthNotValidException;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class AppEcosystemAuthMiddleware extends Middleware {
	private IControllerMethodReflector $reflector;
	private AppEcosystemV2Service $service;
	protected IRequest $request;
	private IL10N $l;
	private LoggerInterface $logger;

	public function __construct(
		IControllerMethodReflector $reflector,
		AppEcosystemV2Service $service,
		IRequest $request,
		IL10N $l,
		LoggerInterface $logger,
	) {
		$this->reflector = $reflector;
		$this->service = $service;
		$this->request = $request;
		$this->l = $l;
		$this->logger = $logger;
	}

	public function beforeController($controller, $methodName) {
		$reflectionMethod = new ReflectionMethod($controller, $methodName);

		$isAppEcosystemAuth = $this->hasAnnotationOrAttribute($reflectionMethod, 'AppEcosystemAuth', AppEcosystemAuth::class);

		if ($isAppEcosystemAuth) {
			if (!$this->service->validateExAppRequestToNC($this->request)) {
				throw new AEAuthNotValidException($this->l->t('AppEcosystemV2 authentication failed'), Http::STATUS_UNAUTHORIZED);
			}
		}
	}

	/**
	 * @template T
	 *
	 * @param ReflectionMethod $reflectionMethod
	 * @param string $annotationName
	 * @param class-string<T> $attributeClass
	 * @return boolean
	 */
	protected function hasAnnotationOrAttribute(ReflectionMethod $reflectionMethod, string $annotationName, string $attributeClass): bool {
		if (!empty($reflectionMethod->getAttributes($attributeClass))) {
			return true;
		}

		if ($this->reflector->hasAnnotation($annotationName)) {
			return true;
		}

		return false;
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
