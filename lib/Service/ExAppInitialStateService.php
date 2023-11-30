<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\UI\InitialStateMapper;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

class ExAppInitialStateService {

	public function __construct(
		private InitialStateMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function setExAppInitialState(string $appId, string $type, string $key, array $value) {
		//	TODO
	}

	public function removeExAppInitialState(string $appId, string $type, string $key) {
		//	TODO
	}

	public function getExAppInitialStates(string $appId, string $type, string $name): ?array {
		try {
			// TODO: Add caching
			$initialStates = $this->mapper->findByAppIdTypeName($appId, $type, $name);
			$results = [];
			foreach ($initialStates as $value) {
				$results[$value['key']] = $value['value'];
			}
			return $results;
		} catch (Exception $e) {
			$this->logger->error($e->getMessage());
			return null;
		}
	}
}
