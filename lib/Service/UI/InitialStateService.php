<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use OCA\AppAPI\Db\UI\InitialState;
use OCA\AppAPI\Db\UI\InitialStateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

class InitialStateService {

	public function __construct(
		private readonly InitialStateMapper $mapper,
		private readonly LoggerInterface    $logger,
	) {
	}

	public function setExAppInitialState(string $appId, string $type, string $name, string $key, array $value): ?InitialState {
		$initialState = $this->getExAppInitialState($appId, $type, $name, $key);
		try {
			$newInitialState = new InitialState([
				'appid' => $appId,
				'type' => $type,
				'name' => $name,
				'key' => $key,
				'value' => $value,
			]);
			if ($initialState !== null) {
				$newInitialState->setId($initialState->getId());
			}
			$initialState = $this->mapper->insertOrUpdate($newInitialState);
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to set ExApp %s initial state %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $initialState;
	}

	public function deleteExAppInitialState(string $appId, string $type, string $name, string $key): bool {
		return $this->mapper->removeByKey($appId, $type, $name, $key);
	}

	public function getExAppInitialState(string $appId, string $type, string $name, string $key): ?InitialState {
		try {
			return $this->mapper->findByAppIdTypeNameKey($appId, $type, $name, $key);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
		}
		return null;
	}

	public function deleteExAppInitialStates(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		return $result;
	}

	public function deleteExAppInitialStatesByTypeName(string $appId, string $type, string $name): int {
		try {
			$result = $this->mapper->removeAllByTypeName($appId, $type, $name);
		} catch (Exception) {
			$result = -1;
		}
		return $result;
	}

	public function getExAppInitialStates(string $appId, string $type, string $name): array {
		try {
			$initialStates = $this->mapper->findByAppIdTypeName($appId, $type, $name);
			$results = [];
			foreach ($initialStates as $value) {
				$results[$value['key']] = $value['value'];
			}
			return $results;
		} catch (Exception) {
			return [];
		}
	}
}
