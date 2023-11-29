<?php

declare(strict_types=1);

namespace OCA\AppAPI\Middleware;

use OCA\AppAPI\Controller\MenuEntryController;
use OCA\AppAPI\Service\AppAPIService;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ExAppUiMiddleware extends Middleware {

	public function __construct(
		private AppAPIService   $service,
		protected IRequest      $request,
		private IL10N           $l,
		private LoggerInterface $logger,
	) {
	}

	public function beforeOutput(Controller $controller, string $methodName, string $output) {
		if (($controller instanceof MenuEntryController) && ($controller->postprocess)) {
			$correctedOutput = preg_replace(
				'/(href=")(\/.*?)(\/app_api\/css\/)(proxy\/.*css")/',
				'$1/index.php/apps/app_api/$4',
				$output);
			foreach ($controller->jsProxyMap as $key => $value) {
				$correctedOutput = preg_replace(
					'/(src=")(\/.*?)(\/app_api\/js\/)(proxy_js\/' . $key . '.js")/',
					'$1/index.php/apps/app_api/proxy/' . $value . '.js"',
					$correctedOutput,
					limit: 1);
			}
			return $correctedOutput;
		}
		return $output;
	}
}
