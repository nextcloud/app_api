<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
		protected IRequest                  $request,
		private readonly INavigationManager $navigationManager,
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
					'/(src=")(\/.*?)(\/app_api\/js\/)(proxy_js\/' . (string)$key . '.js)(.*")/',
					'$1/index.php/apps/app_api/proxy/' . $value . '.js$5',
					$output,
					limit: 1);
			}
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
