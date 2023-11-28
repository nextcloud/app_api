<?php

declare(strict_types=1);

namespace OCA\AppAPI\Middleware;

use Exception;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Controller\MenuEntryController;
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

class ExAppUiMiddleware extends Middleware {

	public function __construct(
		private AppAPIService   $service,
		protected IRequest      $request,
		private IL10N           $l,
		private LoggerInterface $logger,
	) {
	}

	public function beforeOutput(Controller $controller, string $methodName, string $output) {
		if ($controller instanceof MenuEntryController) {
			$adjustedCss = preg_replace(
				'/(href=")(\/.*?)(\/app_api\/css\/)(proxy\/css\/.*css")/',
				'$1/index.php/apps/app_api/$4',
				$output);
			return $adjustedCss;
		}
		return $output;
	}
}
