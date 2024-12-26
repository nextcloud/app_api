<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\ProvidersAI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\Translation\TranslationProvider;
use OCA\AppAPI\Db\Translation\TranslationProviderMapper;
use OCA\AppAPI\Db\Translation\TranslationQueue;
use OCA\AppAPI\Db\Translation\TranslationQueueMapper;
use OCA\AppAPI\PublicFunctions;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IServerContainer;
use OCP\Translation\IDetectLanguageProvider;
use OCP\Translation\ITranslationProviderWithId;
use OCP\Translation\ITranslationProviderWithUserId;
use OCP\Translation\LanguageTuple;
use Psr\Log\LoggerInterface;

class TranslationService {
	private ?ICache $cache = null;

	public function __construct(
		ICacheFactory                              $cacheFactory,
		private readonly TranslationProviderMapper $mapper,
		private readonly LoggerInterface           $logger,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_translation_providers');
		}
	}

	public function registerTranslationProvider(
		string $appId,
		string $name,
		string $displayName,
		array $fromLanguages,
		array $toLanguages,
		string $actionHandler,
		string $actionDetectLang,
	): ?TranslationProvider {
		try {
			$translationProvider = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$translationProvider = null;
		}
		try {
			$newTranslationProvider = new TranslationProvider([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'from_languages' => $fromLanguages,
				'to_languages' => $toLanguages,
				'action_handler' => ltrim($actionHandler, '/'),
				'action_detect_lang' => ltrim($actionDetectLang, '/'),
			]);
			if ($translationProvider !== null) {
				$newTranslationProvider->setId($translationProvider->getId());
			}
			$translationProvider = $this->mapper->insertOrUpdate($newTranslationProvider);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s TranslationProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $translationProvider;
	}

	public function unregisterTranslationProvider(string $appId, string $name): ?TranslationProvider {
		try {
			$translationProvider = $this->getExAppTranslationProvider($appId, $name);
			if ($translationProvider !== null) {
				$this->mapper->delete($translationProvider);
				$this->resetCacheEnabled();
				return $translationProvider;
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s TranslationProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Get list of registered Translation providers (only for enabled ExApps)
	 *
	 * @return TranslationProvider[]
	 */
	public function getRegisteredTranslationProviders(): array {
		try {
			$cacheKey = '/ex_translation_providers';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new TranslationProvider($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppTranslationProvider(string $appId, string $name): ?TranslationProvider {
		foreach ($this->getRegisteredTranslationProviders() as $provider) {
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

	public function unregisterExAppTranslationProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_translation_providers');
	}

	/**
	 * Register ExApp anonymous providers implementations of ITranslationProviderWithId and ITranslationProviderWithUserId
	 * so that they can be used as regular providers in DI container.
	 */
	public function registerExAppTranslationProviders(IRegistrationContext &$context, IServerContainer $serverContainer): void {
		$exAppsProviders = $this->getRegisteredTranslationProviders();
		foreach ($exAppsProviders as $exAppProvider) {
			$class = '\\OCA\\AppAPI\\' . $exAppProvider->getAppid() . '\\' . $exAppProvider->getName();
			// IDetectLanguageProvider implementation is optional if ExApp has action_detect_lang
			if ($exAppProvider->getActionDetectLang() !== '') {
				$provider = $this->getAnonymousExAppIDetectLanguageProvider($exAppProvider, $serverContainer, $class);
			} else {
				$provider = $this->getAnonymousExAppProvider($exAppProvider, $serverContainer, $class);
			}
			$context->registerService($class, function () use ($provider) {
				return $provider;
			});
			$context->registerTranslationProvider($class);
		}
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType
	 */
	private function getAnonymousExAppProvider(TranslationProvider $provider, IServerContainer $serverContainer, string $class): ?ITranslationProviderWithId {
		return new class($provider, $serverContainer, $class) implements ITranslationProviderWithId, ITranslationProviderWithUserId {
			public function __construct(
				TranslationProvider $provider,
				IServerContainer    $serverContainer,
				string              $class,
			) {
				$this->provider = $provider;
				$this->serverContainer = $serverContainer;
				$this->class = $class;
			}

			use TranslationProviderWithIdAndUserId;
		};
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType
	 */
	private function getAnonymousExAppIDetectLanguageProvider(TranslationProvider $provider, IServerContainer $serverContainer, string $class): ?IDetectLanguageProvider {
		return new class($provider, $serverContainer, $class) implements ITranslationProviderWithId, ITranslationProviderWithUserId, IDetectLanguageProvider {
			public function __construct(
				TranslationProvider $provider,
				IServerContainer    $serverContainer,
				string              $class,
			) {
				$this->provider = $provider;
				$this->serverContainer = $serverContainer;
				$this->class = $class;
			}

			use TranslationProviderWithIdAndUserId;

			public function detectLanguage(string $text): ?string {
				/** @var PublicFunctions $service */
				$service = $this->serverContainer->get(PublicFunctions::class);
				$logger = $this->serverContainer->get(LoggerInterface::class);
				$route = $this->provider->getActionDetectLang();

				if ($route === '') {
					return null; // ExApp does not support language detection
				}

				$response = $service->exAppRequest($this->provider->getAppid(),
					$route,
					$this->userId,
					params: [
						'text' => $text,
					],
				);
				$response = json_decode($response->getBody(), true);

				$logger->debug('Detect language response ' . json_encode($response));

				if (isset($response['error'])) {
					throw new \Exception(sprintf('Failed to detect language for text: %s. Error: %s', $text, $response['error']));
				}

				return $response['detected_lang'] ?? null;
			}
		};
	}
}


trait TranslationProviderWithIdAndUserId {
	private ?string $userId;
	private IServerContainer $serverContainer;
	private TranslationProvider $provider;
	private string $class;

	public function getId(): string {
		return $this->class;
	}

	public function getName(): string {
		return $this->provider->getDisplayName();
	}

	public function getAvailableLanguages(): array {
		// $fromLanguages and $toLanguages are JSON objects with lang_code => lang_label paris { "language_code": "language_label" }
		$fromLanguages = $this->provider->getFromLanguages();
		$toLanguages = $this->provider->getToLanguages();
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

	public function translate(?string $fromLanguage, string $toLanguage, string $text, float $maxExecutionTime = 0): string {
		/** @var PublicFunctions $service */
		$service = $this->serverContainer->get(PublicFunctions::class);
		/** @var TranslationQueueMapper $mapper */
		$mapper = $this->serverContainer->get(TranslationQueueMapper::class);
		$route = $this->provider->getActionHandler();
		$queueRecord = $mapper->insert(new TranslationQueue(['created_time' => time()]));
		$taskId = $queueRecord->getId();

		$response = $service->exAppRequest($this->provider->getAppid(),
			$route,
			$this->userId,
			params: [
				'from_language' => $fromLanguage,
				'to_language' => $toLanguage,
				'text' => $text,
				'task_id' => $taskId,
				'max_execution_time' => $maxExecutionTime,
			],
			options: [
				'timeout' => $maxExecutionTime,
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
}
