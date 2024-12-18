<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Middleware;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;

use OCA\AppAPI\Service\ExAppService;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class ExAppUIL10NMiddleware extends Middleware {

	private const routesToLoadL10N = [
		'/files/',
		'/settings/',
		'/app_api/embedded/'
	];

	public function __construct(
		protected IRequest                  $request,
		private readonly IFactory           $l10nFactory,
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
		private readonly IAppManager        $appManager,
		private readonly ExAppService       $exAppService,
		private readonly LoggerInterface	$logger,
	) {
	}

	public function beforeOutput(Controller $controller, string $methodName, string $output) {
		$url = $this->request->getRequestUri();
		$loadL10N = false;
		foreach (self::routesToLoadL10N as $route) {
			$url = str_replace('/index.php', '', $url);
			$url = str_replace('/apps', '', $url);
			if (str_starts_with($url, $route)) {
				$loadL10N = true;
				break;
			}
		}
		if (!$loadL10N) {
			return $output;
		}
		/** @var array $exApp */
		foreach ($this->exAppService->getExAppsList() as $exApp) {
			$appId = $exApp['id'];
			$lang = $this->l10nFactory->findLanguage($appId);
			$availableLocales = $this->l10nFactory->findAvailableLanguages($appId);
			if (in_array($lang, $availableLocales) && $lang !== 'en') {
				$headPos = stripos($output, '</head>');
				if ($headPos !== false) {
					try {
						$l10nScriptSrc = $this->appManager->getAppWebPath($appId) . '/l10n/' . $lang . '.js';
						$nonce = $this->nonceManager->getNonce();
						$output = substr_replace($output, '<script nonce="'.$nonce.'" defer src="' . $l10nScriptSrc . '"></script>', $headPos, 0);
					} catch (AppPathNotFoundException) {
						$this->logger->debug(sprintf('Can not find translations for %s ExApp.', $appId));
					}
				}
			}
		}
		return $output;
	}
}
