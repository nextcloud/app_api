<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\ScriptMapper;
use OCP\DB\Exception;
use OCP\Util;
use Psr\Log\LoggerInterface;

class ExAppScriptsService {

	public function __construct(
		private ScriptMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function addExAppScript(string $appId, string $type, string $path, string $afterAppId) {
		//	TODO
	}

	public function clearExAppScripts(string $appId, string $type) {
		//	TODO
	}

	/**
	 * @throws Exception
	 */
	public function applyExAppScripts(string $appId, string $type): void {
		// TODO: Add caching
		$scripts = $this->mapper->findByAppIdType($appId, $type);
		foreach ($scripts as $value) {
			if (is_null($value['after_app_id'])) {
				Util::addScript(Application::APP_ID, $value['path']);
			}
			else {
				Util::addScript(Application::APP_ID, $value['path'], $value['after_app_id']);
			}
		}
	}
}
