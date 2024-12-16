<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\ProvidersAI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProvider;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProviderMapper;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProviderQueue;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProviderQueueMapper;
use OCA\AppAPI\PublicFunctions;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IServerContainer;
use OCP\SpeechToText\ISpeechToTextProviderWithId;
use OCP\SpeechToText\ISpeechToTextProviderWithUserId;
use Psr\Log\LoggerInterface;

class SpeechToTextService {
	private ?ICache $cache = null;

	public function __construct(
		ICacheFactory                               $cacheFactory,
		private readonly SpeechToTextProviderMapper $mapper,
		private readonly LoggerInterface            $logger,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_speech_to_text_providers');
		}
	}

	public function registerSpeechToTextProvider(string $appId, string $name, string $displayName, string $actionHandler): ?SpeechToTextProvider {
		try {
			$speechToTextProvider = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$speechToTextProvider = null;
		}
		try {
			$newSpeechToTextProvider = new SpeechToTextProvider([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'action_handler' => ltrim($actionHandler, '/'),
			]);
			if ($speechToTextProvider !== null) {
				$newSpeechToTextProvider->setId($speechToTextProvider->getId());
			}
			$speechToTextProvider = $this->mapper->insertOrUpdate($newSpeechToTextProvider);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $speechToTextProvider;
	}

	public function unregisterSpeechToTextProvider(string $appId, string $name): ?SpeechToTextProvider {
		try {
			$speechToTextProvider = $this->getExAppSpeechToTextProvider($appId, $name);
			if ($speechToTextProvider !== null) {
				$this->mapper->delete($speechToTextProvider);
				$this->resetCacheEnabled();
				return $speechToTextProvider;
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Get list of registered SpeechToText providers (only for enabled ExApps)
	 *
	 * @return SpeechToTextProvider[]
	 */
	public function getRegisteredSpeechToTextProviders(): array {
		try {
			$cacheKey = '/ex_speech_to_text_providers';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new SpeechToTextProvider($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppSpeechToTextProvider(string $appId, string $name): ?SpeechToTextProvider {
		foreach ($this->getRegisteredSpeechToTextProviders() as $provider) {
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

	public function unregisterExAppSpeechToTextProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_speech_to_text_providers');
	}

	/**
	 * Register ExApp anonymous providers implementations of ISpeechToTextProviderWithId
	 * so that they can be used as regular providers in DI container.
	 */
	public function registerExAppSpeechToTextProviders(IRegistrationContext &$context, IServerContainer $serverContainer): void {
		$exAppsProviders = $this->getRegisteredSpeechToTextProviders();
		foreach ($exAppsProviders as $exAppProvider) {
			$class = '\\OCA\\AppAPI\\' . $exAppProvider->getAppid() . '\\' . $exAppProvider->getName();
			$sttProvider = $this->getAnonymousExAppProvider($exAppProvider, $serverContainer, $class);
			$context->registerService($class, function () use ($sttProvider) {
				return $sttProvider;
			});
			$context->registerSpeechToTextProvider($class);
		}
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType
	 */
	private function getAnonymousExAppProvider(SpeechToTextProvider $provider, IServerContainer $serverContainer, string $class): ?ISpeechToTextProviderWithId {
		return new class($provider, $serverContainer, $class) implements ISpeechToTextProviderWithId, ISpeechToTextProviderWithUserId {
			private ?string $userId;

			public function __construct(
				private SpeechToTextProvider $provider,
				private IServerContainer     $serverContainer,
				private readonly string      $class,
			) {
			}

			public function getId(): string {
				return $this->class;
			}

			public function getName(): string {
				return $this->provider->getDisplayName();
			}

			public function transcribeFile(File $file, float $maxExecutionTime = 0): string {
				/** @var PublicFunctions $service */
				$service = $this->serverContainer->get(PublicFunctions::class);
				$mapper = $this->serverContainer->get(SpeechToTextProviderQueueMapper::class);
				$route = $this->provider->getActionHandler();
				$queueRecord = $mapper->insert(new SpeechToTextProviderQueue(['created_time' => time()]));
				$taskId = $queueRecord->getId();

				try {
					$fileHandle = $file->fopen('r');
				} catch (Exception $e) {
					throw new \Exception(sprintf('Failed to open file: %s. Error: %s', $file->getName(), $e->getMessage()));
				}
				$response = $service->exAppRequest($this->provider->getAppid(),
					$route,
					$this->userId,
					options: [
						'multipart' => [
							[
								'name' => 'data',
								'contents' => $fileHandle,
								'filename' => $file->getName(),
								'headers' => [
									'Content-Type' => $file->getMimeType(),
								]
							],
						],
						'query' => ['max_execution_time' => $maxExecutionTime, 'task_id' => $taskId],
						'timeout' => $maxExecutionTime,
					]);
				if (is_array($response)) {
					$mapper->delete($mapper->getById($taskId));
					throw new \Exception(sprintf('Failed to process transcribe task: %s with %s:%s. Error: %s',
						$file->getName(),
						$this->provider->getAppid(),
						$this->provider->getName(),
						$response['error']
					));
				}

				do {
					$taskResults = $mapper->getById($taskId);
					usleep(300000); // 0.3s
				} while ($taskResults->getFinished() === 0);

				$mapper->delete($taskResults);
				if (!empty($taskResults->getError())) {
					throw new \Exception(sprintf('Transcribe task returned error: %s:%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$taskResults->getError(),
					));
				}
				return $taskResults->getResult();
			}

			public function setUserId(?string $userId): void {
				$this->userId = $userId;
			}
		};
	}
}
