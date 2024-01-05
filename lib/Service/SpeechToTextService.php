<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProvider;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProviderMapper;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IServerContainer;
use OCP\SpeechToText\ISpeechToTextProviderWithId;
use Psr\Log\LoggerInterface;

class SpeechToTextService {
	private ICache $cache;

	public function __construct(
		ICacheFactory                               $cacheFactory,
		private readonly SpeechToTextProviderMapper $mapper,
		private readonly LoggerInterface            $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_speech_to_text_providers');
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
			$this->cache->set('/ex_speech_to_text_providers_' . $appId . '_' . $name, $speechToTextProvider);
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
			if ($speechToTextProvider === null) {
				return null;
			}
			$this->mapper->delete($speechToTextProvider);
			$this->cache->remove('/ex_speech_to_text_providers_' . $appId . '_' . $name);
			$this->resetCacheEnabled();
			return $speechToTextProvider;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Get list of registered SpeechToText providers (only for enabled ExApps)
	 *
	 * @return SpeechToTextProvider[]
	 */
	public function getRegisteredSpeechToTextProviders(): array {
		try {
			$cacheKey = '/ex_speech_to_text_providers';
			$records = $this->cache->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new SpeechToTextProvider($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppSpeechToTextProvider(string $appId, string $name): ?SpeechToTextProvider {
		$cacheKey = '/ex_speech_to_text_providers_' . $appId . '_' . $name;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache instanceof SpeechToTextProvider ? $cache : new SpeechToTextProvider($cache);
		}

		try {
			$speechToTextProvider = $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
		$this->cache->set($cacheKey, $speechToTextProvider);
		return $speechToTextProvider;
	}

	public function unregisterExAppSpeechToTextProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->cache->clear('/ex_speech_to_text_providers_' . $appId);
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache->remove('/ex_speech_to_text_providers');
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
		return new class($provider, $serverContainer, $class) implements ISpeechToTextProviderWithId {
			private ?string $userId;

			public function __construct(
				private SpeechToTextProvider $sttProvider,
				// We need this to delay the instantiation of AppAPIService during registration to avoid conflicts
				private IServerContainer     $serverContainer, // TODO: Extract needed methods from AppAPIService to be able to use it everytime
				private readonly string      $class,
			) {
			}

			public function getId(): string {
				return $this->class;
			}

			public function getName(): string {
				return $this->sttProvider->getDisplayName();
			}

			public function transcribeFile(File $file, float $maxExecutionTime = 0): string {
				/** @var AppAPIService $service */
				$service = $this->serverContainer->get(AppAPIService::class);
				$route = $this->sttProvider->getActionHandler();

				try {
					$fileHandle = $file->fopen('r');
				} catch (Exception $e) {
					throw new \Exception(sprintf('Failed to open file: %s. Error: %s', $file->getName(), $e->getMessage()));
				}
				$response = $service->requestToExAppById($this->sttProvider->getAppid(),
					$route,
					$this->userId,
					'POST',
					params: ['max_execution_time' => $$maxExecutionTime],
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
						'query' => ['max_execution_time' => $maxExecutionTime],
						'timeout' => $maxExecutionTime,
					]);
				if (is_array($response)) {
					throw new \Exception(sprintf('Failed to transcribe file: %s with %s:%s. Error: %s',
						$file->getName(),
						$this->sttProvider->getAppid(),
						$this->sttProvider->getName(),
						$response['error']
					));
				}
				return $response->getBody();
			}

			public function setUserId(?string $userId): void {
				$this->userId = $userId;
			}
		};
	}
}
