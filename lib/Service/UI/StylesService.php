<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\Style;
use OCA\AppAPI\Db\UI\StyleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Util;
use Psr\Log\LoggerInterface;

class StylesService {

	public function __construct(
		private readonly StyleMapper     $mapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function setExAppStyle(string $appId, string $type, string $name, string $path): ?Style {
		$style = $this->getExAppStyle($appId, $type, $name, $path);
		try {
			$newStyle = new Style([
				'appid' => $appId,
				'type' => $type,
				'name' => $name,
				'path' => ltrim($path, '/'),
			]);
			if ($style !== null) {
				$newStyle->setId($style->getId());
			}
			$style = $this->mapper->insertOrUpdate($newStyle);
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to set ExApp %s script %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $style;
	}

	public function deleteExAppStyle(string $appId, string $type, string $name, string $path): bool {
		return $this->mapper->removeByNameTypePath($appId, $type, $name, ltrim($path, '/'));
	}

	public function getExAppStyle(string $appId, string $type, string $name, string $path): ?Style {
		try {
			return $this->mapper->findByAppIdTypeNamePath($appId, $type, $name, ltrim($path, '/'));
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
		}
		return null;
	}

	public function deleteExAppStylesByTypeName(string $appId, string $type, string $name): int {
		try {
			$result = $this->mapper->removeByTypeName($appId, $type, $name);
		} catch (Exception) {
			$result = -1;
		}
		return $result;
	}

	public function deleteExAppStyles(string $appId): int {
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
	public function applyExAppStyles(string $appId, string $type, string $name): void {
		$styles = $this->mapper->findByAppIdTypeName($appId, $type, $name);
		foreach ($styles as $value) {
			$path = 'proxy/'. $appId . '/' . $value['path'];
			Util::addStyle(Application::APP_ID, $path);
		}
	}
}
