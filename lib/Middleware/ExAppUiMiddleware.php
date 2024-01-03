<?php

declare(strict_types=1);

namespace OCA\AppAPI\Middleware;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Controller\TopMenuController;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\INavigationManager;
use OCP\IRequest;

class ExAppUiMiddleware extends Middleware {

	public function __construct(
		protected IRequest      $request,
		private INavigationManager $navigationManager,
	) {
	}

	public function beforeOutput(Controller $controller, string $methodName, string $output) {
		if (($controller instanceof TopMenuController) && ($controller->postprocess)) {
			$correctedOutput = preg_replace(
				'/(href=")(\/.*?)(\/app_api\/css\/)(proxy\/.*css.*")/',
				'$1/index.php/apps/app_api/$4',
				$output);
			foreach ($controller->jsProxyMap as $key => $value) {
				$correctedOutput = preg_replace(
					'/(src=")(\/.*?)(\/app_api\/js\/)(proxy_js\/' . $key . '.js)(.*")/',
					'$1/index.php/apps/app_api/proxy/' . $value . '.js$5',
					$correctedOutput,
					limit: 1);
			}
			return $correctedOutput;
		}
		return $output;
	}

	public function afterController(Controller $controller, string $methodName, Response $response) {
		if (($controller instanceof TopMenuController) && ($controller->postprocess)) {
			$exAppId = $this->request->getParam('appId');
			$menuEntryName = $this->request->getParam('name');
			// Setting Navigation active entry manually because they have been added dynamically with custom id
			$this->navigationManager->setActiveEntry(Application::APP_ID . '_' . $exAppId . '_' . $menuEntryName);
		}
		return $response;
	}
}
