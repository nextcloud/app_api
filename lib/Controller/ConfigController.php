<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class ConfigController extends Controller {
	private IConfig $config;

	public function __construct(
		string $appName,
		IRequest $request,
		IConfig $config,
	) {
		parent::__construct($appName, $request);

		$this->config = $config;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * Set Admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}
}
