<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use LengthException;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\Script;
use OCA\AppAPI\Db\UI\ScriptMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Util;
use Psr\Log\LoggerInterface;

class ScriptsService {

	public const MAX_JS_FILES = 10; //should be equal to number of files in "proxy_js" folder.

	public function __construct(
		private readonly ScriptMapper    $mapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function setExAppScript(string $appId, string $type, string $name, string $path, string $afterAppId): ?Script {
		$script = $this->getExAppScript($appId, $type, $name, $path);
		try {
			$newScript = new Script([
				'appid' => $appId,
				'type' => $type,
				'name' => $name,
				'path' => ltrim($path, '/'),
				'after_app_id' => $afterAppId,
			]);
			if ($script !== null) {
				$newScript->setId($script->getId());
			}
			$script = $this->mapper->insertOrUpdate($newScript);
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to set ExApp %s script %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $script;
	}

	public function deleteExAppScript(string $appId, string $type, string $name, string $path): bool {
		return $this->mapper->removeByNameTypePath($appId, $type, $name, ltrim($path, '/'));
	}

	public function getExAppScript(string $appId, string $type, string $name, string $path): ?Script {
		try {
			return $this->mapper->findByAppIdTypeNamePath($appId, $type, $name, ltrim($path, '/'));
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
		}
		return null;
	}

	public function deleteExAppScriptsByTypeName(string $appId, string $type, string $name): int {
		try {
			$result = $this->mapper->removeByTypeName($appId, $type, $name);
		} catch (Exception) {
			$result = -1;
		}
		return $result;
	}

	public function deleteExAppScripts(string $appId): int {
		try {
			$result = $this->mapper->removeByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		return $result;
	}

	/**
	 * @throws Exception
	 */
	public function applyExAppScripts(string $appId, string $type, string $name): array {
		$mapResult = [];
		$scripts = $this->mapper->findByAppIdTypeName($appId, $type, $name);
		if (count($scripts) > self::MAX_JS_FILES) {
			throw new LengthException('More than' . (string)self::MAX_JS_FILES . 'JS files on one page are not supported.');
		}

		$i = 0;
		foreach ($scripts as $value) {
			$fakeJsPath = 'proxy_js/' . (string)$i;
			if (empty($value['after_app_id'])) {
				Util::addScript(Application::APP_ID, $fakeJsPath);
			} else {
				Util::addScript(Application::APP_ID, $fakeJsPath, $value['after_app_id']);
			}
			$mapResult[$i] = $appId . '/' . $value['path'];
			$i++;
		}
		return $mapResult;
	}
}
