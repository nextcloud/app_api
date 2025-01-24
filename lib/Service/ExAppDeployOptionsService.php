<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExAppDeployOption;
use OCA\AppAPI\Db\ExAppDeployOptionsMapper;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ExAppDeployOptionsService {

	private ?ICache $cache = null;

	public function __construct(
		private readonly LoggerInterface          $logger,
		private readonly ExAppDeployOptionsMapper $mapper,
		ICacheFactory                             $cacheFactory,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_deploy_options');
		}
	}

	public function addExAppDeployOptions(string $appId, array $deployOptions): array {
		$added = [];
		foreach (array_keys($deployOptions) as $type) {
			if ($this->addExAppDeployOption($appId, $type, $deployOptions[$type])) {
				$added[$type] = $deployOptions[$type];
			}
		}
		return $added;
	}

	public function addExAppDeployOption(
		string $appId,
		string $type,
		mixed  $value,
	): ?ExAppDeployOption {
		$deployOptionEntry = $this->getDeployOption($appId, $type);
		try {
			$newExAppDeployOption = new ExAppDeployOption([
				'appid' => $appId,
				'type' => $type,
				'value' => $value,
			]);
			if ($deployOptionEntry !== null) {
				$newExAppDeployOption->setId($deployOptionEntry->getId());
			}
			$exAppDeployOption = $this->mapper->insertOrUpdate($newExAppDeployOption);
			$this->resetCache();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp Deploy option for %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $exAppDeployOption;
	}

	public function getDeployOption(string $appId, string $type): ?ExAppDeployOption {
		foreach ($this->getDeployOptions() as $deployOption) {
			if (($deployOption->getAppid() === $appId) && ($deployOption->getType() === $type)) {
				return $deployOption;
			}
		}
		return null;
	}

	/**
	 * Get list of all registered ExApp Deploy Options
	 *
	 * @return ExAppDeployOption[]
	 */
	public function getDeployOptions(?string $appId = null): array {
		try {
			$cacheKey = '/ex_deploy_options';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAll();
				$this->cache?->set($cacheKey, $records);
			}
			if ($appId !== null) {
				$records = array_values(array_filter($records, function ($record) use ($appId) {
					return $record['appid'] === $appId;
				}));
			}
			return array_map(function ($record) {
				return new ExAppDeployOption($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function formatDeployOptions(array $deployOptions): array {
		$formattedDeployOptions = [];
		foreach ($deployOptions as $deployOption) {
			$formattedDeployOptions[$deployOption->getType()] = $deployOption->getValue();
		}
		return $formattedDeployOptions;
	}

	public function removeExAppDeployOptions(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCache();
		return $result;
	}

	public function resetCache(): void {
		$this->cache?->remove('/ex_deploy_options');
	}
}
