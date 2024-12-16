<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\ProvidersAI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\TextProcessing\TextProcessingProvider;
use OCA\AppAPI\Db\TextProcessing\TextProcessingProviderMapper;
use OCA\AppAPI\Db\TextProcessing\TextProcessingProviderQueue;
use OCA\AppAPI\Db\TextProcessing\TextProcessingProviderQueueMapper;
use OCA\AppAPI\PublicFunctions;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IServerContainer;
use OCP\TextProcessing\IProviderWithId;
use OCP\TextProcessing\IProviderWithUserId;
use Psr\Log\LoggerInterface;

class TextProcessingService {
	// We do support only available on server-side Text Processing Task Types
	public const TASK_TYPES = [
		'free_prompt' => 'OCP\TextProcessing\FreePromptTaskType',
		'headline' => 'OCP\TextProcessing\HeadlineTaskType',
		'summary' => 'OCP\TextProcessing\SummaryTaskType',
		'topics' => 'OCP\TextProcessing\TopicsTaskType',
	];

	private ?ICache $cache = null;

	public function __construct(
		ICacheFactory                                 $cacheFactory,
		private readonly TextProcessingProviderMapper $mapper,
		private readonly LoggerInterface              $logger,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_text_processing_providers');
		}
	}

	/**
	 * Get list of registered TextProcessing providers (only for enabled ExApps)
	 *
	 * @return TextProcessingProvider[]
	 */
	public function getRegisteredTextProcessingProviders(): array {
		try {
			$cacheKey = '/ex_text_processing_providers';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}

			return array_map(function ($record) {
				return new TextProcessingProvider($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppTextProcessingProvider(string $appId, string $name): ?TextProcessingProvider {
		foreach ($this->getRegisteredTextProcessingProviders() as $provider) {
			if (($provider->getAppid() === $appId) && ($provider->getName() === $name)) {
				return $provider;
			}
		}
		try {
			return $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function registerTextProcessingProvider(
		string $appId,
		string $name,
		string $displayName,
		string $actionHandler,
		string $taskType
	): ?TextProcessingProvider {
		try {
			$textProcessingProvider = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$textProcessingProvider = null;
		}
		try {
			if (!$this->isTaskTypeValid($taskType)) {
				return null;
			}

			$newTextProcessingProvider = new TextProcessingProvider([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'action_handler' => ltrim($actionHandler, '/'),
				'task_type' => $taskType,
			]);

			if ($textProcessingProvider !== null) {
				$newTextProcessingProvider->setId($textProcessingProvider->getId());
			}

			$textProcessingProvider = $this->mapper->insertOrUpdate($newTextProcessingProvider);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s TextProcessingProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $textProcessingProvider;
	}

	public function unregisterTextProcessingProvider(string $appId, string $name): ?TextProcessingProvider {
		try {
			$textProcessingProvider = $this->getExAppTextProcessingProvider($appId, $name);
			if ($textProcessingProvider !== null) {
				$this->mapper->delete($textProcessingProvider);
				$this->resetCacheEnabled();
				return $textProcessingProvider;
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s TextProcessingProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Register dynamically ExApps TextProcessing providers with ID using anonymous classes.
	 *
	 * @param IRegistrationContext $context
	 * @param IServerContainer $serverContainer
	 *
	 * @return void
	 */
	public function registerExAppTextProcessingProviders(IRegistrationContext &$context, IServerContainer $serverContainer): void {
		$exAppsProviders = $this->getRegisteredTextProcessingProviders();
		/** @var TextProcessingProvider $exAppProvider */
		foreach ($exAppsProviders as $exAppProvider) {
			if (!$this->isTaskTypeValid($exAppProvider->getTaskType())) {
				continue;
			}

			$className = '\\OCA\\AppAPI\\' . $exAppProvider->getAppid() . '\\' . $exAppProvider->getName();
			$provider = $this->getAnonymousExAppProvider($exAppProvider, $className, $serverContainer);
			$context->registerService($className, function () use ($provider) {
				return $provider;
			});
			$context->registerTextProcessingProvider($className);
		}
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType, MissingTemplateParam
	 */
	private function getAnonymousExAppProvider(
		TextProcessingProvider $provider,
		string $className,
		IServerContainer $serverContainer
	): IProviderWithId {
		return new class($provider, $serverContainer, $className) implements IProviderWithId, IProviderWithUserId {
			private ?string $userId;

			public function __construct(
				private readonly TextProcessingProvider 		   $provider,
				private readonly IServerContainer       		   $serverContainer,
				private readonly string                 		   $className,
			) {
			}

			public function getId(): string {
				return $this->className;
			}

			public function getName(): string {
				return $this->provider->getDisplayName();
			}

			public function process(string $prompt, float $maxExecutionTime = 0): string {
				/** @var PublicFunctions $service */
				$service = $this->serverContainer->get(PublicFunctions::class);
				$mapper = $this->serverContainer->get(TextProcessingProviderQueueMapper::class);
				$route = $this->provider->getActionHandler();
				$queueRecord = $mapper->insert(new TextProcessingProviderQueue(['created_time' => time()]));
				$taskId = $queueRecord->getId();

				$response = $service->exAppRequest($this->provider->getAppid(),
					$route,
					$this->userId,
					params: [
						'prompt' => $prompt,
						'task_id' => $taskId,
						'max_execution_time' => $maxExecutionTime,
					],
					options: [
						'timeout' => $maxExecutionTime,
					],
				);
				if (is_array($response)) {
					$mapper->delete($mapper->getById($taskId));
					throw new \Exception(sprintf('Failed process text task: %s:%s:%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$this->provider->getTaskType(),
						$response['error']
					));
				}

				do {
					$taskResults = $mapper->getById($taskId);
					usleep(300000); // 0.3s
				} while ($taskResults->getFinished() === 0);

				$mapper->delete($taskResults);
				if (!empty($taskResults->getError())) {
					throw new \Exception(sprintf('Text task returned error: %s:%s:%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$this->provider->getTaskType(),
						$taskResults->getError(),
					));
				}
				return $taskResults->getResult();
			}

			public function getTaskType(): string {
				return TextProcessingService::TASK_TYPES[$this->provider->getTaskType()];
			}

			public function setUserId(?string $userId): void {
				$this->userId = $userId;
			}
		};
	}

	private function isTaskTypeValid(string $getActionType): bool {
		return in_array($getActionType, array_keys(self::TASK_TYPES));
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_text_processing_providers');
	}

	public function unregisterExAppTextProcessingProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}
}
