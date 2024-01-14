<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service\ProvidersAI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\Translation\TranslationProvider;
use OCA\AppAPI\Db\Translation\TranslationProviderMapper;
use OCA\AppAPI\Db\Translation\TranslationQueue;
use OCA\AppAPI\Db\Translation\TranslationQueueMapper;
use OCA\AppAPI\Service\AppAPIService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IServerContainer;
use OCP\Translation\ITranslationProviderWithId;
use OCP\Translation\ITranslationProviderWithUserId;
use OCP\Translation\LanguageTuple;
use Psr\Log\LoggerInterface;

class TranslationService {
	private ICache $cache;

	public function __construct(
		ICacheFactory                                     $cacheFactory,
		private readonly TranslationProviderMapper $mapper,
		private readonly LoggerInterface                  $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_translation_providers');
	}

	public function registerTranslationProvider(
		string $appId,
		string $name,
		string $displayName,
		array $fromLanguages,
		array $toLanguages,
		string $actionHandler
	): ?TranslationProvider {
		try {
			$speechToTextProvider = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$speechToTextProvider = null;
		}
		try {
			$newSpeechToTextProvider = new TranslationProvider([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'from_languages' => json_encode($fromLanguages),
				'to_languages' => json_encode($toLanguages),
				'action_handler' => ltrim($actionHandler, '/'),
			]);
			if ($speechToTextProvider !== null) {
				$newSpeechToTextProvider->setId($speechToTextProvider->getId());
			}
			$speechToTextProvider = $this->mapper->insertOrUpdate($newSpeechToTextProvider);
			$this->cache->set('/ex_translation_providers_' . $appId . '_' . $name, $speechToTextProvider);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $speechToTextProvider;
	}

	public function unregisterTranslationProvider(string $appId, string $name): ?TranslationProvider {
		try {
			$TranslationProvider = $this->getExAppTranslationProvider($appId, $name);
			if ($TranslationProvider === null) {
				return null;
			}
			$this->mapper->delete($TranslationProvider);
			$this->cache->remove('/ex_translation_providers_' . $appId . '_' . $name);
			$this->resetCacheEnabled();
			return $TranslationProvider;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Get list of registered Translation providers (only for enabled ExApps)
	 *
	 * @return TranslationProvider[]
	 */
	public function getRegisteredTranslationProviders(): array {
		try {
			$cacheKey = '/ex_translation_providers';
			$records = $this->cache->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new TranslationProvider($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppTranslationProvider(string $appId, string $name): ?TranslationProvider {
		$cacheKey = '/ex_translation_providers_' . $appId . '_' . $name;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache instanceof TranslationProvider ? $cache : new TranslationProvider($cache);
		}

		try {
			$TranslationProvider = $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
		$this->cache->set($cacheKey, $TranslationProvider);
		return $TranslationProvider;
	}

	public function unregisterExAppTranslationProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->cache->clear('/ex_translation_providers_' . $appId);
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache->remove('/ex_translation_providers');
	}

	/**
	 * Register ExApp anonymous providers implementations of ITranslationProviderWithId and ITranslationProviderWithUserId
	 * so that they can be used as regular providers in DI container.
	 */
	public function registerExAppTranslationProviders(IRegistrationContext &$context, IServerContainer $serverContainer): void {
		$exAppsProviders = $this->getRegisteredTranslationProviders();
		foreach ($exAppsProviders as $exAppProvider) {
			$class = '\\OCA\\AppAPI\\' . $exAppProvider->getAppid() . '\\' . $exAppProvider->getName();
			$provider = $this->getAnonymousExAppProvider($exAppProvider, $serverContainer, $class);
			$context->registerService($class, function () use ($provider) {
				return $provider;
			});
			$context->registerSpeechToTextProvider($class);
		}
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType
	 */
	private function getAnonymousExAppProvider(TranslationProvider $provider, IServerContainer $serverContainer, string $class): ?ITranslationProviderWithId {
		return new class($provider, $serverContainer, $class) implements ITranslationProviderWithId, ITranslationProviderWithUserId {
			private ?string $userId;

			public function __construct(
				private TranslationProvider $provider,
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

			public function getAvailableLanguages(): array {
				// $fromLanguages and $toLanguages are JSON objects with lang_code => lang_label paris { "language_code": "language_label" }
				$fromLanguages = json_decode($this->provider->getFromLanguages(), true);
				$toLanguages = json_decode($this->provider->getToLanguages(), true);
				// Convert JSON objects to array of all possible LanguageTuple pairs
				$availableLanguages = [];
				foreach ($fromLanguages as $fromLanguageCode => $fromLanguageLabel) {
					foreach ($toLanguages as $toLanguageCode => $toLanguageLabel) {
						if ($fromLanguageCode === $toLanguageCode) {
							continue;
						}
						$availableLanguages[] = LanguageTuple::fromArray([
							'from' => $fromLanguageCode,
							'fromLabel' => $fromLanguageLabel,
							'to' => $toLanguageCode,
							'toLabel' => $toLanguageLabel,
						]);
					}
				}
				return $availableLanguages;
			}

			public function translate(?string $fromLanguage, string $toLanguage, string $text): string {
				/** @var AppAPIService $service */
				$service = $this->serverContainer->get(AppAPIService::class);
				/** @var TranslationQueueMapper $mapper */
				$mapper = $this->serverContainer->get(TranslationQueueMapper::class);
				$route = $this->provider->getActionHandler();
				$queueRecord = $mapper->insert(new TranslationQueue(['created_time' => time()]));
				$taskId = $queueRecord->getId();

				$response = $service->requestToExAppById($this->provider->getAppid(),
					$route,
					$this->userId,
					params: [
						'from_language' => $fromLanguage,
						'to_language' => $toLanguage,
						'text' => $text,
					],
				);

				if (is_array($response)) {
					$mapper->delete($mapper->getById($taskId));
					throw new \Exception(sprintf('Failed to process translation task: %s:%s:%s-%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$fromLanguage,
						$toLanguage,
						$response['error']
					));
				}

				do {
					$taskResults = $mapper->getById($taskId);
					usleep(300000); // 0.3s
				} while ($taskResults->getFinished() === 0);

				$mapper->delete($taskResults);
				if (!empty($taskResults->getError())) {
					throw new \Exception(sprintf('Translation task returned error: %s:%s:%s-%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$fromLanguage,
						$toLanguage,
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