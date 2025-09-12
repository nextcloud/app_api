<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\Console\ExAppOccCommand;
use OCA\AppAPI\Db\Console\ExAppOccCommandMapper;
use OCA\AppAPI\PublicFunctions;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExAppOccService {

	private ?ICache $cache = null;

	public function __construct(
		private readonly LoggerInterface       $logger,
		private readonly ExAppOccCommandMapper $mapper,
		ICacheFactory                          $cacheFactory,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_occ_commands');
		}
	}

	public function registerCommand(
		string $appId,
		string $name,
		string $description,
		int $hidden,
		array $arguments,
		array $options,
		array $usages,
		string $executeHandler
	): ?ExAppOccCommand {
		$occCommandEntry = $this->getOccCommand($appId, $name);
		try {
			$newOccCommandEntry = new ExAppOccCommand([
				'appid' => $appId,
				'name' => $name,
				'description' => $description,
				'hidden' => $hidden,
				'arguments' => $arguments,
				'options' => $options,
				'usages' => $usages,
				'execute_handler' => ltrim($executeHandler, '/'),
			]);
			if ($occCommandEntry !== null) {
				$newOccCommandEntry->setId($occCommandEntry->getId());
			}
			$occCommandEntry = $this->mapper->insertOrUpdate($newOccCommandEntry);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp OCC command for %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $occCommandEntry;
	}

	public function unregisterCommand(string $appId, string $name): bool {
		if (!$this->mapper->removeByAppIdOccName($appId, $name)) {
			return false;
		}
		$this->resetCacheEnabled();
		return true;
	}

	public function getOccCommand(string $appId, string $name): ?ExAppOccCommand {
		foreach ($this->getOccCommands() as $occCommand) {
			if (($occCommand->getAppid() === $appId) && ($occCommand->getName() === $name)) {
				return $occCommand;
			}
		}
		return null;
	}

	/**
	 * Get list of registered ExApp OCC Commands (only for enabled ExApps)
	 *
	 * @return ExAppOccCommand[]
	 */
	public function getOccCommands(): array {
		try {
			$cacheKey = '/ex_occ_commands';
			$records = $this->cache?->get($cacheKey);
			if (!is_array($records)) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new ExAppOccCommand($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function buildCommand(ExAppOccCommand $occCommand, ContainerInterface $container): Command {
		return new class($occCommand, $container) extends Command {
			private LoggerInterface $logger;
			private PublicFunctions $service;

			public function __construct(
				private ExAppOccCommand $occCommand,
				private ContainerInterface $container
			) {
				parent::__construct();

				$this->logger = $this->container->get(LoggerInterface::class);
				$this->service = $this->container->get(PublicFunctions::class);
			}

			protected function configure() {
				$this->setName($this->occCommand->getName());
				$this->setDescription($this->occCommand->getDescription());
				$this->setHidden(filter_var($this->occCommand->getHidden(), FILTER_VALIDATE_BOOLEAN));
				foreach ($this->occCommand->getArguments() as $argument) {
					$this->addArgument(
						$argument['name'],
						$this->buildArgumentMode($argument['mode']),
						$argument['description'],
						in_array($argument['mode'], ['optional', 'array']) ? $argument['default'] : null,
					);
				}
				foreach ($this->occCommand->getOptions() as $option) {
					$this->addOption(
						$option['name'],
						$option['shortcut'] ?? null,
						$this->buildOptionMode($option['mode']),
						$option['description'],
						$this->buildOptionMode($option['mode']) !== InputOption::VALUE_NONE
							? $option['default'] ?? null
							: null
					);
				}
				foreach ($this->occCommand->getUsages() as $usage) {
					$this->addUsage($usage);
				}
			}

			protected function execute(InputInterface $input, OutputInterface $output): int {
				if (count($this->occCommand->getArguments()) > 0) {
					$arguments = [];
					foreach ($this->occCommand->getArguments() as $argument) {
						$arguments[$argument['name']] = $input->getArgument($argument['name']);
					}
				} else {
					$arguments = null;
				}
				if (count($this->occCommand->getOptions()) > 0) {
					$options = [];
					foreach ($this->occCommand->getOptions() as $option) {
						$options[$option['name']] = $input->getOption($option['name']);
					}
				} else {
					$options = null;
				}

				$executeHandler = $this->occCommand->getExecuteHandler();
				$response = $this->service->exAppRequest($this->occCommand->getAppid(), $executeHandler,
					params: [
						'occ' => [
							'arguments' => $arguments,
							'options' => $options,
						],
					],
					options: [
						'stream' => true,
						'timeout' => 0,
					]
				);

				if (is_array($response) && isset($response['error'])) {
					$output->writeln(sprintf('[%s] command executeHandler failed. Error: %s', $this->occCommand->getName(), $response['error']));
					$this->logger->error(sprintf('[%s] command executeHandler failed. Error: %s', $this->occCommand->getName(), $response['error']), [
						'app' => $this->occCommand->getAppid(),
					]);
					return 1;
				}

				if ($response->getStatusCode() !== Http::STATUS_OK) {
					$output->writeln(sprintf('[%s] command executeHandler failed', $this->occCommand->getName()));
					$this->logger->error(sprintf('[%s] command executeHandler failed', $this->occCommand->getName()), [
						'app' => $this->occCommand->getAppid(),
					]);
					return 1;
				}

				$body = $response->getBody();
				if (is_resource($body)) {
					while (!feof($body)) {
						$output->write(fread($body, 1024));
					}
				}

				return 0;
			}

			private function buildArgumentMode(string $mode): int {
				$modes = explode(',', $mode);
				$argumentMode = 0;
				foreach ($modes as $mode) {
					$argumentMode |= $this->_buildArgumentMode($mode);
				}
				return $argumentMode;
			}

			private function _buildArgumentMode(string $mode): int {
				if ($mode === 'required') {
					return InputArgument::REQUIRED;
				}
				if ($mode === 'optional') {
					return InputArgument::OPTIONAL;
				}
				if ($mode === 'array') {
					return InputArgument::IS_ARRAY;
				}
				return InputArgument::OPTIONAL;
			}

			private function buildOptionMode(string $mode): int {
				if ($mode === 'required') {
					return InputOption::VALUE_REQUIRED;
				}
				if ($mode === 'optional') {
					return InputOption::VALUE_OPTIONAL;
				}
				if ($mode === 'none') {
					return InputOption::VALUE_NONE;
				}
				if ($mode === 'array') {
					return InputOption::VALUE_IS_ARRAY;
				}
				if ($mode === 'negatable') {
					return InputOption::VALUE_NEGATABLE;
				}
				return InputOption::VALUE_NONE;
			}
		};
	}

	public function unregisterExAppOccCommands(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_occ_commands');
	}
}
