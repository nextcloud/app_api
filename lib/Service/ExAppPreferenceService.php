<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExAppPreference;
use OCA\AppAPI\Db\ExAppPreferenceMapper;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

/**
 * App per-user preferences (preferences_ex)
 */
class ExAppPreferenceService {

	public function __construct(
		private ExAppPreferenceMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function setUserConfigValue(string $userId, string $appId, string $configKey, mixed $configValue) {
		try {
			$exAppPreference = $this->mapper->findByUserIdAppIdKey($userId, $appId, $configKey);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$exAppPreference = null;
		}
		if ($exAppPreference === null) {
			try {
				return $this->mapper->insert(new ExAppPreference([
					'userid' => $userId,
					'appid' => $appId,
					'configkey' => $configKey,
					'configvalue' => $configValue ?? '',
				]));
			} catch (Exception $e) {
				$this->logger->error('Error while inserting new config value: ' . $e->getMessage(), ['exception' => $e]);
				return null;
			}
		} else {
			$exAppPreference->setConfigvalue($configValue);
			try {
				if ($this->mapper->updateUserConfigValue($exAppPreference) !== 1) {
					$this->logger->error('Error while updating preferences_ex config value');
					return null;
				}
				return $exAppPreference;
			} catch (Exception $e) {
				$this->logger->error('Error while updating config value: ' . $e->getMessage(), ['exception' => $e]);
				return null;
			}
		}
	}

	public function getUserConfigValues(string $userId, string $appId, array $configKeys): ?array {
		try {
			return array_map(function (ExAppPreference $exAppPreference) {
				return [
					'configkey' => $exAppPreference->getConfigkey(),
					'configvalue' => $exAppPreference->getConfigvalue() ?? '',
				];
			}, $this->mapper->findByUserIdAppIdKeys($userId, $appId, $configKeys));
		} catch (Exception) {
			return null;
		}
	}

	public function deleteUserConfigValues(array $configKeys, string $userId, string $appId): int {
		try {
			return $this->mapper->deleteUserConfigValues($configKeys, $userId, $appId);
		} catch (Exception) {
			return -1;
		}
	}
}
