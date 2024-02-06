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
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class ExAppUiMiddleware extends Middleware {

	public function __construct(
		protected IRequest                  $request,
		private readonly INavigationManager $navigationManager,
		private readonly IFactory           $l10nFactory,
		private readonly IURLGenerator      $urlGenerator,
	) {
	}

	public function beforeOutput(Controller $controller, string $methodName, string $output) {
		if (($controller instanceof TopMenuController) && ($controller->postprocess)) {
			$output = preg_replace(
				'/(href=")(\/.*?)(\/app_api\/css\/)(proxy\/.*css.*")/',
				'$1/index.php/apps/app_api/$4',
				$output);
			foreach ($controller->jsProxyMap as $key => $value) {
				$output = preg_replace(
					'/(src=")(\/.*?)(\/app_api\/js\/)(proxy_js\/' . $key . '.js)(.*")/',
					'$1/index.php/apps/app_api/proxy/' . $value . '.js$5',
					$output,
					limit: 1);
			}
			// Attach current locale ExApp l10n
			$appId = $this->request->getParam('appId');
			$lang = $this->l10nFactory->findLanguage($appId);
			$headPos = stripos($output, '</head>');
			$l10nScriptSrc = $this->urlGenerator->linkToRoute('app_api.ExAppProxy.ExAppGet', ['appId' => $appId, 'other' => 'js/' . $lang . '.js']);
			$output = substr_replace($output, '<script nonce="" defer="" src="' . $l10nScriptSrc . '"></script>', $headPos, 0);
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
