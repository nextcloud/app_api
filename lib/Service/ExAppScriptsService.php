<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use LengthException;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\ScriptMapper;
use OCP\DB\Exception;
use OCP\Util;
use Psr\Log\LoggerInterface;

class ExAppScriptsService {

	public const MAX_JS_FILES = 20 + 1; //should be equal to number of files in "proxy_js" folder.

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
	public function applyExAppScripts(string $appId, string $type, string $name): array {
		// TODO: Add caching
		$mapResult = [];
		$scripts = $this->mapper->findByAppIdTypeName($appId, $type, $name);
		if (count($scripts) > self::MAX_JS_FILES) {
			throw new LengthException('More than' . self::MAX_JS_FILES . 'JS files on one page are not supported.');
		}

		$i = 0;
		foreach ($scripts as $value) {
			$fakeJsPath = 'proxy_js/' . $i;
			if (is_null($value['after_app_id'])) {
				Util::addScript(Application::APP_ID, $fakeJsPath);
			} else {
				Util::addScript(Application::APP_ID, $fakeJsPath, $value['after_app_id']);
			}
			if (str_starts_with($value['path'], '/')) {
				$mapResult[$i] = $appId . $value['path'];
			} else {
				$mapResult[$i] = $appId . '/' . $value['path'];
			}
			$i++;
		}
		return $mapResult;
	}
}
