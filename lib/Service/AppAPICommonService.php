<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
use OCP\App\IAppManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AppAPICommonService {

	public function __construct(
		private readonly IAppManager         $appManager,
		private readonly DaemonConfigService $daemonConfigService,
		private readonly LoggerInterface     $logger,
		private readonly HarpService         $harpService,
	) {
	}

	public function buildAppAPIAuthHeaders(?IRequest $request, ?string $userId, ExApp $exApp): array {
		$headers = [
			'AA-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
			'EX-APP-ID' => $exApp->getAppid(),
			'EX-APP-VERSION' => $exApp->getVersion(),
			'AUTHORIZATION-APP-API' => base64_encode($userId . ':' . $exApp->getSecret()),
			'AA-REQUEST-ID' => $request instanceof IRequest ? $request->getId() : 'CLI',
		];

		// todo: cache
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByAppId($exApp->getAppid());
		if ($daemonConfig === null) {
			// this should not happen
			$this->logger->error(sprintf('Daemon config with name %s not found.', $exApp->getDaemonConfigName()));
			return $headers;
		}
		if ($this->harpService->isHarp($daemonConfig)) {
			$harpKey = $this->harpService->getHarpSharedKey($daemonConfig);
			$headers['HARP-SHARED-KEY'] = $harpKey;
			$headers['EX-APP-PORT'] = $exApp->getPort();
		}

		return $headers;
	}

	public function buildExAppHost(array $deployConfig): string {
		if (isset($deployConfig['additional_options']['OVERRIDE_APP_HOST'])) {
			return $deployConfig['additional_options']['OVERRIDE_APP_HOST'];
		}
		if (isset($deployConfig['net'])) {
			if ($deployConfig['net'] === 'host') {
				return '127.0.0.1';  # ExApp using this host network, it is visible for Nextcloud on loop-back adapter
			}
			return '0.0.0.0';
		}
		return '127.0.0.1';  # fallback to loop-back adapter
	}
}
