<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Listener;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\HarpService;
use OCA\AppAPI\Service\UI\ReferenceProviderService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Loads the picker scripts of ExApp reference providers on pages that can show the Smart Picker.
 *
 * The scripts must be on the page when the picker opens: the picker hides a provider that has neither
 * a search provider nor a registered custom element.
 *
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class RenderReferenceListener implements IEventListener {
	public function __construct(
		private readonly ReferenceProviderService $referenceProviderService,
		private readonly ExAppService $exAppService,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}

		$pickers = [];
		foreach ($this->referenceProviderService->getEnabledReferenceProviders() as $provider) {
			$scriptPath = $provider->getScriptPath() ?? '';
			if ($scriptPath === '') {
				continue;
			}
			$exApp = $this->exAppService->getExApp($provider->getAppid());
			if ($exApp === null) {
				continue;
			}
			$baseUrl = $this->getExAppBaseUrl($provider->getAppid(), HarpService::isHarp($exApp->getDeployConfig()));
			$pickers[] = [
				'appid' => $provider->getAppid(),
				'provider_id' => $provider->getProviderId(),
				'base_url' => $baseUrl,
				'script_url' => $baseUrl . '/' . $scriptPath,
			];
		}

		if (empty($pickers)) {
			return;
		}
		$this->initialState->provideInitialState('ex_reference_pickers', $pickers);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
	}

	/**
	 * Base URL the browser uses to reach the ExApp: HaRP serves `/exapps/<appid>` directly,
	 * otherwise the request goes through the AppAPI PHP proxy.
	 */
	private function getExAppBaseUrl(string $appId, bool $isHarp): string {
		if ($isHarp) {
			return rtrim($this->urlGenerator->getWebroot(), '/') . '/exapps/' . $appId;
		}
		return rtrim($this->urlGenerator->linkToRoute('app_api.ExAppProxy.ExAppGet', ['appId' => $appId, 'other' => '']), '/');
	}
}
